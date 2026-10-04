<?php

namespace App\Domains\Purchasing\Actions;

use App\Domains\Accounting\Actions\PostSystemJournal;
use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Purchasing\Models\Supplier;
use App\Domains\Purchasing\Models\SupplierInvoice;
use App\Domains\Purchasing\Models\SupplierPayment;
use App\Domains\StoreSetup\Actions\CurrencySnapshot;
use App\Domains\StoreSetup\Actions\NextDocumentNumber;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Support\BusinessException;
use App\Support\Decimal;
use App\Support\Posting;
use Illuminate\Support\Facades\DB;

class PaySupplier
{
    public function save(array $data, int $actor): SupplierPayment
    {
        return DB::transaction(function () use ($data, $actor) {
            $store = StoreSetting::sharedCurrent();
            $supplier = Supplier::lockForUpdate()->findOrFail($data['supplier_id']);
            if (! $supplier->active) {
                throw new BusinessException('SUPPLIER_INACTIVE', 'المورد غير نشط.');
            }
            $fx = app(CurrencySnapshot::class)->execute($data['currency'], $data['document_date'], $store->base_currency);
            Posting::treasury($data['method'], (int) $data[$data['method'] === 'cash' ? 'cashbox_id' : 'bank_account_id'], $data['currency']);
            if (Decimal::cmp($data['amount'], '0') <= 0) {
                throw new BusinessException('AMOUNT_REQUIRED', 'أدخل مبلغ دفعة موجباً.');
            }
            $doc = SupplierPayment::create([...$fx, 'supplier_id' => $supplier->id, 'supplier_snapshot' => $supplier->documentIdentity(), 'document_date' => $data['document_date'], 'reason' => $data['reason'], 'method' => $data['method'], 'cashbox_id' => $data['method'] === 'cash' ? $data['cashbox_id'] : null, 'bank_account_id' => $data['method'] === 'bank' ? $data['bank_account_id'] : null, 'payment_reference' => $data['payment_reference'] ?? null, 'amount' => $data['amount'], 'base_amount' => Decimal::mul($data['amount'], $fx['exchange_rate']), 'payload' => ['allocations' => $data['allocations'] ?? []], 'created_by' => $actor]);
            app(RecordAudit::class)->execute('purchasing.payment_created', 'supplier_payment', $doc->id, null, $doc->toArray(), $actor);

            return $doc;
        }, 5);
    }

    public function post(int $id, int $actor): SupplierPayment
    {
        return DB::transaction(function () use ($id, $actor) {
            $doc = SupplierPayment::lockForUpdate()->findOrFail($id);
            if ($doc->status === 'posted') {
                return $doc->load('allocations');
            }
            Posting::period($doc->document_date);
            $supplier = Supplier::lockForUpdate()->findOrFail($doc->supplier_id);
            if (! $supplier->active) {
                throw new BusinessException('SUPPLIER_INACTIVE', 'المورد غير نشط.');
            }
            $treasury = Posting::treasury($doc->method, $doc->method === 'cash' ? $doc->cashbox_id : $doc->bank_account_id, $doc->currency);
            $requests = collect($doc->payload['allocations'])->keyBy('supplier_invoice_id');
            $invoices = SupplierInvoice::where('supplier_id', $doc->supplier_id)->where('currency', $doc->currency)->where('status', 'posted')->when($requests->isNotEmpty(), fn ($q) => $q->whereIn('id', $requests->keys()))->orderBy('due_date')->orderBy('id')->lockForUpdate()->get();
            if ($requests->isNotEmpty() && $invoices->count() !== $requests->count()) {
                throw new BusinessException('PAYMENT_INVOICE_MISMATCH', 'اختر فواتير مرحّلة لنفس المورد والعملة.');
            }
            $left = $doc->amount;
            $carry = '0';
            $gl = [];
            foreach ($invoices as $invoice) {
                $b = app(SupplierBalances::class)->invoice($invoice);
                $amount = $requests->isNotEmpty() ? $requests[$invoice->id]['amount'] : (Decimal::cmp($left, $b['remaining']) < 0 ? $left : $b['remaining']);
                if (Decimal::cmp($amount, '0') <= 0) {
                    continue;
                }
                if ($invoice->posting_date > $doc->document_date || Decimal::cmp($amount, $left) > 0 || Decimal::cmp($amount, $b['remaining']) > 0) {
                    throw new BusinessException('PAYMENT_ALLOCATION_INVALID', 'توزيع الدفعة يتجاوز الرصيد أو يسبق الفاتورة.');
                }
                $base = Posting::portion($invoice->base_total, $invoice->foreign_total, $b['settled'], $amount, $b['settled_base']);
                $doc->allocations()->create(['supplier_invoice_id' => $invoice->id, 'amount' => $amount, 'base_amount' => $base]);
                $gl[] = Posting::line('ap', $base, $amount, $invoice->exchange_rate);
                $carry = Decimal::add($carry, $base);
                $left = Decimal::sub($left, $amount);
            }
            if (Decimal::cmp($left, '0') !== 0) {
                throw new BusinessException('PAYMENT_UNALLOCATED', 'يجب توزيع كامل الدفعة على فواتير مفتوحة.');
            }
            $gl[] = Posting::line($treasury, Decimal::sub('0', $doc->base_amount), Decimal::sub('0', $doc->amount));
            $gl[] = Posting::line('exchange_difference', Decimal::sub($doc->base_amount, $carry), '0');
            $entry = app(PostSystemJournal::class)->execute('supplier_payment', $id, 'payment', $doc->document_date, $doc->reason, $gl, $actor, 'payments', $doc->only(['currency', 'exchange_rate', 'exchange_rate_date', 'exchange_rate_source']));
            $number = app(NextDocumentNumber::class)->execute('payment_voucher', $doc->document_date);
            DB::table('supplier_ledger_entries')->insert(['supplier_id' => $doc->supplier_id, 'source_type' => 'supplier_payment', 'source_id' => $id, 'event' => 'payment', 'document_no' => $number, 'posting_date' => $doc->document_date, 'currency' => $doc->currency, 'foreign_amount' => Decimal::sub('0', $doc->amount), 'base_amount' => Decimal::sub('0', $carry), 'exchange_rate' => $doc->exchange_rate, 'account_id' => Posting::account('ap', 'liability'), 'journal_entry_id' => $entry->id, 'created_by' => $actor, 'created_at' => now()]);
            $doc->update(['status' => 'posted', 'document_no' => $number, 'posted_at' => now(), 'posted_by' => $actor, 'posted_journal_entry_id' => $entry->id]);
            app(RecordAudit::class)->execute('purchasing.payment_posted','supplier_payment',$id,null,['document_no' => $number, 'amount' => $doc->amount, 'journal_entry_id' => $entry->id],$actor);

            return $doc->fresh('allocations');
        }, 5);
    }
}
