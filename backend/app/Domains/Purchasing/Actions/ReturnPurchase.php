<?php

namespace App\Domains\Purchasing\Actions;

use App\Domains\Accounting\Actions\PostSystemJournal;
use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Catalog\Models\Product;
use App\Domains\Inventory\Actions\StockLedger;
use App\Domains\Inventory\Models\StockLocation;
use App\Domains\Purchasing\Models\Supplier;
use App\Domains\Purchasing\Models\SupplierCreditNote;
use App\Domains\Purchasing\Models\SupplierInvoice;
use App\Domains\StoreSetup\Actions\NextDocumentNumber;
use App\Support\BusinessException;
use App\Support\Decimal;
use App\Support\Posting;
use Illuminate\Support\Facades\DB;

class ReturnPurchase
{
    public function save(array $data, int $actor): SupplierCreditNote
    {
        return DB::transaction(function () use ($data, $actor) {
            $invoice = SupplierInvoice::lockForUpdate()->findOrFail($data['supplier_invoice_id']);
            if ($invoice->status !== 'posted' || $data['document_date'] < $invoice->posting_date) {
                throw new BusinessException('RETURN_SOURCE_INVALID', 'اختر فاتورة مرحّلة وتاريخاً يساوي تاريخها أو يليه.');
            }
            $doc = SupplierCreditNote::create([...$invoice->only(['supplier_id', 'supplier_snapshot', 'currency', 'base_currency', 'exchange_rate', 'exchange_rate_date', 'exchange_rate_source']), 'supplier_invoice_id' => $invoice->id, 'document_date' => $data['document_date'], 'reason' => $data['reason'], 'payload' => ['lines' => $data['lines']], 'created_by' => $actor]);
            app(RecordAudit::class)->execute('purchasing.return_created', 'supplier_credit_note', $doc->id, null, $doc->toArray(), $actor);

            return $doc;
        }, 5);
    }

    public function post(int $id, int $actor): SupplierCreditNote
    {
        return DB::transaction(function () use ($id, $actor) {
            $doc = SupplierCreditNote::lockForUpdate()->findOrFail($id);
            if ($doc->status === 'posted') {
                return $doc->load('lines');
            }
            Posting::period($doc->document_date);
            Supplier::lockForUpdate()->findOrFail($doc->supplier_id);
            $invoice = SupplierInvoice::with('lines')->lockForUpdate()->findOrFail($doc->supplier_invoice_id);
            $total = '0';
            $baseTotal = '0';
            $gl = []; $taxRows = [];
            foreach ($doc->payload['lines'] as $raw) {
                $original = $invoice->lines->firstWhere('id', (int) $raw['supplier_invoice_line_id']);
                if (! $original) {
                    throw new BusinessException('RETURN_LINE_MISMATCH', 'بند المرتجع لا ينتمي للفاتورة المحددة.');
                }
                $product = Product::with('unit')->lockForUpdate()->findOrFail($original->product_id);
                $location = StockLocation::sharedLock()->findOrFail($raw['location_id']);
                $serials = array_map([StockLedger::class, 'normalizeSerial'], $raw['serials'] ?? []);
                $receipt = DB::table('goods_receipt_lines')->where('id', $original->goods_receipt_line_id)->first();
                $receiptSerials = json_decode($receipt->serials, true, 512, JSON_THROW_ON_ERROR);
                foreach ($serials as $serial) {
                    if (! in_array($serial, $receiptSerials, true)) {
                        throw new BusinessException('RETURN_SERIAL_SOURCE', 'الرقم التسلسلي لا ينتمي إلى الاستلام الأصلي.');
                    }
                }
                $used = DB::table('supplier_credit_note_lines')->where('supplier_invoice_line_id', $original->id)->lockForUpdate()->get();
                $qty = '0';
                $values = array_fill_keys(['net', 'tax', 'amount', 'base_net', 'base_tax', 'base_amount'], '0');
                foreach ($used as $row) {
                    $qty = Decimal::add($qty, $row->quantity);
                    foreach ($values as $f => $v) {
                        $values[$f] = Decimal::add($values[$f], $row->$f);
                    }
                }
                $map = ['net' => 'taxable_base', 'tax' => 'tax_amount', 'amount' => 'total', 'base_net' => 'base_net', 'base_tax' => 'base_tax', 'base_amount' => 'base_total'];
                $line = ['supplier_invoice_line_id' => $original->id, 'location_id' => $location->id, 'serials' => $serials, 'quantity' => $raw['quantity'], 'product_snapshot' => $original->product_snapshot, 'stock_cost' => '0'];
                foreach ($map as $target => $source) {
                    $line[$target] = Posting::portion($original->$source, $original->quantity, $qty, $raw['quantity'], $values[$target]);
                }
                // Base components can each round independently; the tax and net snapshots define the returned total.
                $line['amount'] = Decimal::add($line['net'], $line['tax']);
                $line['base_amount'] = Decimal::add($line['base_net'], $line['base_tax']);
                $record = $doc->lines()->create($line);
                $movement = app(StockLedger::class)->apply($product, $location, 'out', $raw['quantity'], null, $serials, ['type' => 'supplier_credit_note', 'id' => $id, 'line_id' => $record->id, 'event' => 'return', 'date' => $doc->document_date, 'movement_type' => 'purchase_return'], $actor);
                $record->update(['stock_cost' => $movement->total_cost, 'inventory_movement_id' => $movement->id]);
                $gl[] = Posting::line('inventory', Decimal::sub('0', $movement->total_cost), '0');
                $gl[] = Posting::line('inventory_adjustment', Decimal::sub($movement->total_cost, $line['base_net']), Decimal::sub('0', $line['net']));
                if (Decimal::cmp($line['base_tax'], '0') !== 0) {
                    $gl[] = Posting::line((int) $original->tax_account_id, Decimal::sub('0', $line['base_tax']), Decimal::sub('0', $line['tax']));
                }
                $gl[] = Posting::line('ap', $line['base_amount'], $line['amount']);
                $total = Decimal::add($total, $line['amount']);
                $baseTotal = Decimal::add($baseTotal, $line['base_amount']);
                $taxRows[] = ['source_type' => 'supplier_credit_note', 'source_id' => $id, 'source_line_id' => $record->id, 'event' => 'return', 'direction' => 'input', 'document_date' => $doc->document_date, 'posting_date' => $doc->document_date, 'tax_code_id' => $original->tax_code_id, 'tax_snapshot' => json_encode($original->tax_snapshot, JSON_THROW_ON_ERROR), 'currency' => $doc->currency, 'exchange_rate' => $doc->exchange_rate, 'tax_rate' => $original->tax_rate, 'tax_inclusive' => $original->tax_inclusive, 'tax_recoverable' => $original->tax_recoverable, 'taxable_base' => Decimal::sub('0', $line['net']), 'tax_amount' => Decimal::sub('0', $line['tax']), 'base_taxable_amount' => Decimal::sub('0', $line['base_net']), 'base_tax_amount' => Decimal::sub('0', $line['base_tax']), 'recoverable_base_amount' => $original->tax_recoverable ? Decimal::sub('0', $line['base_tax']) : '0', 'account_id' => $original->tax_account_id, 'created_by' => $actor, 'created_at' => now()];
            }
            $entry = collect($gl)->contains(fn($l)=>Decimal::cmp($l['debit'],'0')>0||Decimal::cmp($l['credit'],'0')>0)?app(PostSystemJournal::class)->execute('supplier_credit_note', $id, 'return', $doc->document_date, $doc->reason, $gl, $actor, 'purchases', $doc->only(['currency', 'exchange_rate', 'exchange_rate_date', 'exchange_rate_source'])) : null;
            foreach($taxRows as $row)DB::table('tax_transactions')->insert([...$row,'journal_entry_id'=>$entry?->id]);
            $number = app(NextDocumentNumber::class)->execute('supplier_return', $doc->document_date);
            DB::table('supplier_ledger_entries')->insert(['supplier_id' => $doc->supplier_id, 'source_type' => 'supplier_credit_note', 'source_id' => $id, 'event' => 'return', 'document_no' => $number, 'posting_date' => $doc->document_date, 'currency' => $doc->currency, 'foreign_amount' => Decimal::sub('0', $total), 'base_amount' => Decimal::sub('0', $baseTotal), 'exchange_rate' => $doc->exchange_rate, 'account_id' => Posting::account('ap', 'liability'), 'journal_entry_id' => $entry?->id, 'created_by' => $actor, 'created_at' => now()]);
            $doc->update(['status' => 'posted', 'amount' => $total, 'base_amount' => $baseTotal, 'document_no' => $number, 'posted_at' => now(), 'posted_by' => $actor, 'posted_journal_entry_id' => $entry?->id]);
            app(RecordAudit::class)->execute('purchasing.return_posted','supplier_credit_note',$id,null,['document_no' => $number, 'amount' => $total, 'journal_entry_id' => $entry?->id],$actor);

            return $doc->fresh('lines');
        }, 5);
    }
}
