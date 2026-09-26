<?php

namespace App\Domains\Purchasing\Actions;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Purchasing\Models\GoodsReceipt;
use App\Domains\Purchasing\Models\PurchaseOrder;
use App\Domains\Purchasing\Models\Supplier;
use App\Domains\StoreSetup\Actions\CurrencySnapshot;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Models\User;
use App\Support\BusinessException;
use Illuminate\Support\Facades\DB;

class SaveGoodsReceipt
{
    public function execute(array $data, int $actorId, ?int $id = null): GoodsReceipt
    {
        return DB::transaction(function () use ($data, $actorId, $id) {
            $store = StoreSetting::sharedLock()->findOrFail(1);
            $doc = $id ? GoodsReceipt::with('lines')->lockForUpdate()->findOrFail($id) : new GoodsReceipt;
            if ($id && ($doc->status !== 'draft' || $doc->version !== (int) $data['version'])) {
                throw new BusinessException('RECEIPT_NOT_EDITABLE', 'سند الاستلام مرحّل أو تغيرت نسخته.', 409);
            }
            $order = isset($data['purchase_order_id']) ? PurchaseOrder::with('lines')->lockForUpdate()->findOrFail($data['purchase_order_id']) : null;
            self::validateSource($order, (int) $data['supplier_id'], $data['currency'], $data['document_date'], $actorId);
            $supplier = Supplier::sharedLock()->findOrFail($data['supplier_id']);
            if (! $supplier->active) {
                throw new BusinessException('SUPPLIER_INACTIVE', 'المورد غير نشط.');
            }
            $fx = app(CurrencySnapshot::class)->execute($data['currency'], $data['document_date'], $store->base_currency);
            $prepared = app(PrepareGoodsReceiptLines::class)->execute($data['lines'], $order, $fx['exchange_rate']);
            $before = $doc->exists ? $doc->toArray() : null;
            $doc->fill(['document_date' => $data['document_date'], 'supplier_id' => $supplier->id, 'supplier_snapshot' => $order?->supplier_snapshot ?? $supplier->documentIdentity(), 'purchase_order_id' => $order?->id, 'delivery_reference' => $data['delivery_reference'], 'notes' => $data['notes'] ?? null, ...$fx, 'foreign_total' => $prepared['foreign_total'], 'base_total' => $prepared['base_total'], 'status' => 'draft', 'version' => ($doc->version ?? 0) + 1, 'created_by' => $doc->created_by ?? $actorId, 'updated_by' => $actorId])->save();
            $doc->lines()->delete();
            $doc->lines()->createMany($prepared['lines']);
            app(RecordAudit::class)->execute($id ? 'purchasing.receipt_updated' : 'purchasing.receipt_created', 'goods_receipt', $doc->id, $before, $doc->fresh('lines')->toArray(), $actorId);

            return $doc->fresh(['lines.location', 'purchaseOrder:id,document_no']);
        }, 5);
    }

    public static function validateSource(?PurchaseOrder $order, int $supplierId, string $currency, string $date, int $actorId): void
    {
        if (! $order) {
            if (! User::findOrFail($actorId)->hasPermission('purchasing.receive_without_po')) {
                throw new BusinessException('DIRECT_RECEIPT_FORBIDDEN', 'الاستلام دون أمر شراء يحتاج صلاحية خاصة.', 403);
            }

return;
        }
        if ($order->status !== 'issued' || $order->supplier_id !== $supplierId || $order->currency !== $currency || $order->document_date > $date) {
            throw new BusinessException('PURCHASE_ORDER_RECEIPT_MISMATCH','الاستلام يتطلب أمر شراء صادراً بنفس المورد والعملة وتاريخ لاحق أو مطابق.');
        }
    }
}
