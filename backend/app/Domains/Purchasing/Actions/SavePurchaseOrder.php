<?php

namespace App\Domains\Purchasing\Actions;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Catalog\Models\Product;
use App\Domains\Inventory\Actions\StockLedger;
use App\Domains\Purchasing\Models\PurchaseOrder;
use App\Domains\Purchasing\Models\Supplier;
use App\Domains\StoreSetup\Actions\CurrencySnapshot;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Domains\Tax\Actions\CalculateDocumentTax;
use App\Support\BusinessException;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

class SavePurchaseOrder
{
    public function execute(array $data, int $actorId, ?int $id = null): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $actorId, $id) {
            $store = StoreSetting::sharedLock()->findOrFail(1);
            $doc = $id ? PurchaseOrder::with(['lines', 'approval'])->lockForUpdate()->findOrFail($id) : new PurchaseOrder;
            if ($id && ($doc->status === 'issued' || $doc->version !== (int) $data['version'])) {
                throw new BusinessException('PURCHASE_ORDER_NOT_EDITABLE', 'أمر الشراء صادر أو تغيرت نسخته. أعد تحميله.', 409);
            }
            $supplier = Supplier::sharedLock()->findOrFail($data['supplier_id']);
            if (! $supplier->active) {
                throw new BusinessException('SUPPLIER_INACTIVE', 'المورد غير نشط.');
            }
            $fx = app(CurrencySnapshot::class)->execute($data['currency'], $data['document_date'], $store->base_currency);
            $before = $id ? $doc->toArray() : null;
            $products = Product::with('unit')->whereIn('id', array_column($data['lines'], 'product_id'))->orderBy('id')->sharedLock()->get()->keyBy('id');
            $lines = [];
            $subtotal = '0.0000';
            $taxTotal = '0.0000';
            foreach ($data['lines'] as $line) {
                $product = $products[$line['product_id']];
                if (! $product->active) {
                    throw new BusinessException('PRODUCT_INACTIVE', 'المنتج غير نشط.');
                }
                app(StockLedger::class)->validateQuantity($product, $line['quantity']);
                $tax = app(CalculateDocumentTax::class)->execute($line, $data['document_date']);
                $lines[] = ['product_id' => $product->id, 'product_snapshot' => [...$product->only(['id', 'sku', 'name_ar', 'manufacturer_model', 'unit_id', 'serial_tracked']), 'unit_name' => $product->unit->name_ar], 'quantity' => Decimal::money($line['quantity']), 'unit_price' => Decimal::money($line['unit_price']), 'discount_amount' => Decimal::money($line['discount_amount']), ...$tax];
                $subtotal = Decimal::add($subtotal, $tax['taxable_base']);
                $taxTotal = Decimal::add($taxTotal, $tax['tax_amount']);
            }
            $total = Decimal::add($subtotal, $taxTotal);
            $baseTotal = Decimal::mul($total, $fx['exchange_rate']);
            if (Decimal::cmp($total, '99999999999999.9999') > 0 || Decimal::cmp($baseTotal, '99999999999999.9999') > 0) {
                throw new BusinessException('AMOUNT_OUT_OF_RANGE', 'قيمة المستند تتجاوز الحد المسموح.');
            }
            if ($doc->approval) {
                $doc->approval->update(['status' => 'superseded']);
            }
            $doc->fill(['document_date' => $data['document_date'], 'expected_on' => $data['expected_on'] ?? null, 'supplier_id' => $supplier->id, 'supplier_snapshot' => $supplier->documentIdentity(), 'supplier_reference' => $data['supplier_reference'] ?? null, 'notes' => $data['notes'] ?? null, ...$fx, 'subtotal' => $subtotal, 'tax_total' => $taxTotal, 'total' => $total, 'base_total' => $baseTotal, 'status' => 'draft', 'version' => ($doc->version ?? 0) + 1, 'approval_id' => null, 'approved_payload_hash' => null, 'approval_policy_version' => null, 'created_by' => $doc->created_by ?? $actorId, 'updated_by' => $actorId])->save();
            $doc->lines()->delete();
            $doc->lines()->createMany($lines);
            app(RecordAudit::class)->execute($id ? 'purchasing.order_updated' : 'purchasing.order_created', 'purchase_order', $doc->id, $before, $doc->fresh('lines')->toArray(), $actorId);

            return $doc->fresh(['lines', 'approval']);
        }, 5);
    }
}
