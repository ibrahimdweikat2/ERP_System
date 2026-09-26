<?php

namespace App\Domains\Purchasing\Actions;

use App\Domains\Accounting\Actions\PostSystemJournal;
use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\AccountingPeriod;
use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Purchasing\Models\PurchasingInvoicePolicy;
use App\Domains\Purchasing\Models\Supplier;
use App\Domains\Purchasing\Models\SupplierInvoice;
use App\Domains\StoreSetup\Actions\NextDocumentNumber;
use App\Domains\StoreSetup\Models\Currency;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Support\BusinessException;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

class PostSupplierInvoice
{
    public function execute(int $id, int $version, int $actorId): SupplierInvoice
    {
        return DB::transaction(function () use ($id, $version, $actorId) {
            $store = StoreSetting::sharedLock()->findOrFail(1);
            $policy = PurchasingInvoicePolicy::sharedLock()->findOrFail(1);
            $doc = SupplierInvoice::with('lines')->lockForUpdate()->findOrFail($id);
            if ($doc->version !== $version) {
                throw new BusinessException('DOCUMENT_VERSION_CONFLICT', 'تغيرت الفاتورة. أعد تحميلها.', 409);
            }
            if ($doc->status === 'posted') {
                return $doc;
            }
            if ($doc->policy_version !== $policy->version) {
                throw new BusinessException('INVOICE_POLICY_CHANGED', 'تغيرت سياسة الفواتير. راجع المسودة واحفظها مجدداً.', 409);
            }
            $period = AccountingPeriod::where('starts_on', '<=', $doc->posting_date)->where('ends_on', '>=', $doc->posting_date)->lockForUpdate()->first();
            if (! $period || $period->status !== 'open') {
                throw new BusinessException('PERIOD_NOT_OPEN', 'الفاتورة تتطلب فترة ترحيل مفتوحة.');
            }
            if (! Supplier::whereKey($doc->supplier_id)->where('active', true)->sharedLock()->first()) {
                throw new BusinessException('SUPPLIER_INACTIVE', 'المورد غير نشط.');
            }
            if (! Currency::where('code', $doc->currency)->where('is_active', true)->sharedLock()->first()) {
                throw new BusinessException('CURRENCY_INACTIVE', 'العملة غير نشطة.');
            }
            if ($policy->require_attachment && ! DB::table('document_attachments')->where(['entity_type' => 'supplier_invoice', 'entity_id' => $id])->lockForUpdate()->first()) {
                throw new BusinessException('INVOICE_ATTACHMENT_REQUIRED', 'أرفق نسخة فاتورة المورد قبل الترحيل.');
            }
            $prepared = app(PrepareSupplierInvoiceLines::class)->execute($doc->lines->toArray(), $doc->supplier_id, $doc->currency, $doc->posting_date, $doc->invoice_date, $doc->exchange_rate, true);
            $gl = [];
            $apId = DB::table('account_mappings')->where('key', 'ap')->value('account_id');
            $ap = Account::sharedLock()->find($apId);
            if (! $ap || ! $ap->active || ! $ap->is_control_account || $ap->account_type !== 'liability') {
                throw new BusinessException('AP_ACCOUNT_INVALID', 'أكمل ربط حساب ذمم الموردين الرقابي النشط.');
            }
            foreach ($doc->lines->values() as $index => $record) {
                $line = $prepared['lines'][$index];
                $priceForeign = Decimal::sub($line['taxable_base'], $line['receipt_foreign_value']);
                if (Decimal::cmp($priceForeign, '0') !== 0) {
                    if ($policy->price_variance_mode !== 'post_to_expense') {
                        throw new BusinessException('PURCHASE_PRICE_VARIANCE_BLOCKED', 'سعر الفاتورة يختلف عن الاستلام؛ راجع الفاتورة أو سياسة فرق السعر.');
                    }
                    if (mb_strlen(trim($doc->variance_reason ?? '')) < 5) {
                        throw new BusinessException('VARIANCE_REASON_REQUIRED', 'وثق سبب قبول فرق سعر الشراء.');
                    }
                    $this->expenseAccount($policy->price_variance_account_id);
                    $gl[] = $this->signed(['account_id' => $policy->price_variance_account_id], $line['price_variance'], $priceForeign);
                }
                $gl[] = [...$this->signed(['mapping' => 'grni'], $line['receipt_base_value'], $line['receipt_foreign_value']), 'exchange_rate' => $line['receipt_exchange_rate']];
                $gl[] = $this->signed(['mapping' => 'exchange_difference'], $line['fx_variance'], '0');
                if (Decimal::cmp($line['tax_amount'], '0') > 0) {
                    if ($line['tax_recoverable']) {
                        if (! $store->vat_registered) {
                            throw new BusinessException('INPUT_VAT_NOT_ELIGIBLE', 'استرداد ضريبة المدخلات يتطلب متجراً مسجلاً للضريبة.');
                        }
                        $taxAccount = Account::sharedLock()->find($line['tax_account_id']);
                        $reserved = DB::table('account_mappings')->where('account_id', $line['tax_account_id'])->whereNotIn('key', ['vat_input'])->first();
                        if (! $taxAccount || ! $taxAccount->active || ! $taxAccount->is_control_account || $taxAccount->account_type !== 'asset' || $reserved) {
                            throw new BusinessException('INPUT_VAT_ACCOUNT_INVALID', 'رمز الضريبة يحتاج حساب مدخلات رقابياً نشطاً غير مستخدم لذمم أو مخزون.');
                        }
                    } else {
                        if ($policy->nonrecoverable_tax_mode !== 'expense') {
                            throw new BusinessException('NONRECOVERABLE_TAX_BLOCKED', 'راجع سياسة الضريبة غير القابلة للاسترداد قبل الترحيل.');
                        }
                        $this->expenseAccount($policy->nonrecoverable_tax_account_id);
                        $line['tax_account_id'] = $policy->nonrecoverable_tax_account_id;
                    }
                    $gl[] = $this->signed(['account_id' => $line['tax_account_id']], $line['base_tax'], $line['tax_amount']);
                }
                $record->update($line);
            }
            $gl[] = $this->signed(['account_id' => $ap->id], Decimal::sub('0', $prepared['base_total']), Decimal::sub('0', $prepared['foreign_total']));
            $nonzero = array_filter($gl, fn ($line) => Decimal::cmp($line['debit'], '0') !== 0 || Decimal::cmp($line['credit'], '0') !== 0);
            $journal = $nonzero ? app(PostSystemJournal::class)->execute('supplier_invoice', $id, 'invoice', $doc->posting_date, 'فاتورة مورد — '.$doc->supplier_invoice_no, array_values($nonzero), $actorId, 'purchases', $doc->only(['currency', 'exchange_rate', 'exchange_rate_date', 'exchange_rate_source'])) : null;
            unset($prepared['lines']);
            $number = app(NextDocumentNumber::class)->execute('supplier_invoice', $doc->posting_date);
            DB::table('supplier_ledger_entries')->insert(['supplier_id' => $doc->supplier_id, 'source_type' => 'supplier_invoice', 'source_id' => $id, 'event' => 'invoice', 'document_no' => $number, 'posting_date' => $doc->posting_date, 'due_date' => $doc->due_date, 'currency' => $doc->currency, 'foreign_amount' => $prepared['foreign_total'], 'base_amount' => $prepared['base_total'], 'exchange_rate' => $doc->exchange_rate, 'account_id' => $ap->id, 'journal_entry_id' => $journal?->id, 'created_by' => $actorId, 'created_at' => now()]);
            foreach ($doc->fresh('lines')->lines as $line) {
                DB::table('tax_transactions')->insert(['source_type' => 'supplier_invoice', 'source_id' => $id, 'source_line_id' => $line->id, 'event' => 'invoice', 'direction' => 'input', 'document_date' => $doc->invoice_date, 'posting_date' => $doc->posting_date, 'tax_code_id' => $line->tax_code_id, 'tax_snapshot' => json_encode($line->tax_snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'currency' => $doc->currency, 'exchange_rate' => $doc->exchange_rate, 'tax_rate' => $line->tax_rate, 'tax_inclusive' => $line->tax_inclusive, 'tax_recoverable' => $line->tax_recoverable, 'taxable_base' => $line->taxable_base, 'tax_amount' => $line->tax_amount, 'base_taxable_amount' => $line->base_net, 'base_tax_amount' => $line->base_tax, 'recoverable_base_amount' => $line->tax_recoverable ? $line->base_tax : '0', 'account_id' => $line->tax_account_id, 'journal_entry_id' => $journal?->id, 'created_by' => $actorId, 'created_at' => now()]);
            }
            $doc->update([...$prepared, 'document_no' => $number, 'status' => 'posted', 'posted_by' => $actorId, 'posted_at' => now(), 'posted_journal_entry_id' => $journal?->id]);
            app(RecordAudit::class)->execute('purchasing.invoice_posted', 'supplier_invoice', $id, null, ['document_no' => $number, 'supplier_invoice_no' => $doc->supplier_invoice_no, 'foreign_total' => $doc->foreign_total, 'base_total' => $doc->base_total, 'price_variance' => $doc->price_variance_total, 'fx_variance' => $doc->fx_variance_total, 'variance_reason' => $doc->variance_reason, 'journal_entry_id' => $journal?->id], $actorId);

            return $doc->fresh('lines');
        }, 5);
    }

    private function signed(array $account, string $base, string $foreign): array
    {
        return [...$account, 'debit' => Decimal::cmp($base, '0') > 0 ? $base : '0', 'credit' => Decimal::cmp($base, '0') < 0 ? Decimal::sub('0', $base) : '0', 'foreign_amount' => $foreign];
    }

    private function expenseAccount(?int $id): void
    {
        $account = $id ? Account::sharedLock()->find($id) : null;
        if (! $account || ! $account->active || $account->account_type !== 'expense' || $account->is_control_account) {
            throw new BusinessException('PURCHASE_EXPENSE_ACCOUNT_INVALID','اختر حساب مصروف نشطاً غير رقابي للمعالجة المعتمدة.');
        }
    }
}
