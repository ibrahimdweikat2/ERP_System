<?php

namespace App\Domains\Purchasing\Actions;

use App\Domains\Accounting\Actions\PostSystemJournal;
use App\Domains\Accounting\Models\AccountingPeriod;
use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Catalog\Models\Product;
use App\Domains\Inventory\Actions\StockLedger;
use App\Domains\Inventory\Models\StockLocation;
use App\Domains\Purchasing\Models\GoodsReceipt;
use App\Domains\Purchasing\Models\PurchaseOrder;
use App\Domains\Purchasing\Models\Supplier;
use App\Domains\StoreSetup\Actions\NextDocumentNumber;
use App\Domains\StoreSetup\Models\Currency;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Support\BusinessException;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

class PostGoodsReceipt
{
    public function execute(int $id, int $version, int $actorId): GoodsReceipt
    {
        return DB::transaction(function () use ($id, $version, $actorId) {
            StoreSetting::sharedLock()->findOrFail(1);
            $doc = GoodsReceipt::with('lines')->lockForUpdate()->findOrFail($id);
            if ($doc->version !== $version) {
                throw new BusinessException('DOCUMENT_VERSION_CONFLICT', 'تغير سند الاستلام. أعد تحميله.', 409);
            }
            if ($doc->status === 'posted') {
                return $doc->load(['lines.location', 'purchaseOrder:id,document_no']);
            }
            $period = AccountingPeriod::where('starts_on', '<=', $doc->document_date)->where('ends_on', '>=', $doc->document_date)->lockForUpdate()->first();
            if (! $period || $period->status !== 'open') {
                throw new BusinessException('PERIOD_NOT_OPEN', 'الاستلام يتطلب فترة مالية مفتوحة.');
            }
            $order = $doc->purchase_order_id ? PurchaseOrder::with('lines')->lockForUpdate()->findOrFail($doc->purchase_order_id) : null;
            SaveGoodsReceipt::validateSource($order, $doc->supplier_id, $doc->currency, $doc->document_date, $actorId);
            if (! Supplier::whereKey($doc->supplier_id)->where('active', true)->sharedLock()->first()) {
                throw new BusinessException('SUPPLIER_INACTIVE', 'المورد غير نشط.');
            }
            if (! Currency::where('code', $doc->currency)->where('is_active', true)->sharedLock()->first()) {
                throw new BusinessException('CURRENCY_INACTIVE', 'العملة غير نشطة.');
            }
            $prepared = app(PrepareGoodsReceiptLines::class)->execute($doc->lines->toArray(), $order, $doc->exchange_rate);
            foreach ($doc->lines->values() as $i => $line) {
                $data = $prepared['lines'][$i];
                $movement = app(StockLedger::class)->apply(Product::with('unit')->findOrFail($line->product_id), StockLocation::findOrFail($line->location_id), 'in', $line->quantity, $data['base_value'], $line->serials, ['type' => 'goods_receipt', 'id' => $doc->id, 'line_id' => $line->id, 'event' => 'receive', 'movement_type' => 'purchase_receipt', 'date' => $doc->document_date], $actorId);
                $line->update([...$data, 'posted_movement_id' => $movement->id]);
            }
            $journal = Decimal::cmp($prepared['base_total'], '0') > 0 ? app(PostSystemJournal::class)->execute('goods_receipt', $id, 'receive', $doc->document_date, 'استلام بضاعة — '.$doc->supplier_snapshot['legal_name'], [['mapping' => 'inventory', 'debit' => $prepared['base_total'], 'credit' => '0', 'foreign_amount' => $prepared['foreign_total']], ['mapping' => 'grni', 'debit' => '0', 'credit' => $prepared['base_total'], 'foreign_amount' => Decimal::sub('0', $prepared['foreign_total'])]], $actorId, 'purchases', $doc->only(['currency', 'exchange_rate', 'exchange_rate_date', 'exchange_rate_source'])) : null;
            $doc->update(['document_no' => app(NextDocumentNumber::class)->execute('goods_receipt', $doc->document_date), 'status' => 'posted', 'foreign_total' => $prepared['foreign_total'], 'base_total' => $prepared['base_total'], 'posted_by' => $actorId, 'posted_at' => now(), 'posted_journal_entry_id' => $journal?->id]);
            app(RecordAudit::class)->execute('purchasing.receipt_posted', 'goods_receipt', $id, null, ['document_no' => $doc->document_no, 'purchase_order_id' => $doc->purchase_order_id, 'foreign_total' => $doc->foreign_total, 'base_total' => $doc->base_total, 'journal_entry_id' => $journal?->id], $actorId);

            return $doc->fresh(['lines.location', 'purchaseOrder:id,document_no']);
        }, 5);
    }
}
