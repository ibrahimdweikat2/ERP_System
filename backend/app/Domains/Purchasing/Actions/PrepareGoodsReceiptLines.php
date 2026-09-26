<?php

namespace App\Domains\Purchasing\Actions;

use App\Domains\Catalog\Models\Product;
use App\Domains\Inventory\Actions\StockLedger;
use App\Domains\Inventory\Models\StockLocation;
use App\Domains\Purchasing\Models\PurchaseOrder;
use App\Support\BusinessException;
use App\Support\Decimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

class PrepareGoodsReceiptLines
{
    public function execute(array $rawLines, ?PurchaseOrder $order, string $rate): array
    {
        // The caller owns the PO header lock, serializing allocation of its remaining quantities.
        $locations = StockLocation::whereIn('id', array_column($rawLines, 'location_id'))->orderBy('id')->sharedLock()->get()->keyBy('id');
        $products = Product::with('unit')->whereIn('id', array_column($rawLines, 'product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $used = [];
        $seen = [];
        $result = [];
        $foreignTotal = '0.0000';
        $baseTotal = '0.0000';
        if ($order) {
            $rows = DB::table('goods_receipt_lines as l')->join('goods_receipts as r', 'r.id', '=', 'l.goods_receipt_id')->where('r.purchase_order_id', $order->id)->where('r.status', 'posted')->orderBy('l.id')->lockForUpdate()->get(['l.purchase_order_line_id', 'l.quantity', 'l.foreign_value']);
            foreach ($rows as $row) {
                $key = $row->purchase_order_line_id;
                $used[$key] = ['quantity' => Decimal::add($used[$key]['quantity'] ?? '0', $row->quantity), 'value' => Decimal::add($used[$key]['value'] ?? '0', $row->foreign_value)];
            }
        }
        foreach ($rawLines as $raw) {
            $product = $products[$raw['product_id']];
            $location = $locations[$raw['location_id']];
            if (! $product->active || ! $location->active) {
                throw new BusinessException('STOCK_MASTER_INACTIVE', 'المنتج أو الموقع غير نشط.');
            }
            if ($raw['condition'] !== 'new' && ($location->sellable || ! in_array($location->purpose, ['returns', 'damaged'], true))) {
                throw new BusinessException('RECEIPT_CONDITION_LOCATION', 'الأجهزة التالفة أو المفتوحة تُستلم في موقع فحص أو تالف غير متاح للبيع.');
            }
            if ($raw['condition'] !== 'new' && empty($raw['condition_notes'])) {
                throw new BusinessException('CONDITION_NOTES_REQUIRED', 'أدخل وصف حالة الجهاز التالف أو المفتوح.');
            }
            $qty = Decimal::money($raw['quantity']);
            $serials = array_map(StockLedger::normalizeSerial(...), $raw['serials']);
            app(StockLedger::class)->validateQuantity($product, $qty);
            app(StockLedger::class)->validateSerials($product, $qty, $serials);
            foreach ($serials as $serial) {
                if (isset($seen[$serial])) {
                    throw new BusinessException('DUPLICATE_SERIAL', 'رقم تسلسلي مكرر في المستند.');
                }$seen[$serial] = true;
            }
            $poLine = $order?->lines->firstWhere('id', $raw['purchase_order_line_id'] ?? 0);
            if ($order) {
                if (! $poLine || $poLine->product_id !== $product->id) {
                    throw new BusinessException('PURCHASE_ORDER_LINE_MISMATCH', 'البند لا يطابق أمر الشراء والمنتج.');
                }
                if ((int) $poLine->product_snapshot['unit_id'] !== $product->unit_id) {
                    throw new BusinessException('PURCHASE_UNIT_CHANGED', 'تغيرت وحدة المنتج منذ إصدار أمر الشراء.');
                }
                $allocated = $used[$poLine->id] ?? ['quantity' => '0', 'value' => '0'];
                $nextQty = Decimal::add($allocated['quantity'], $qty);
                if (Decimal::cmp($nextQty, $poLine->quantity) > 0) {
                    throw new BusinessException('PURCHASE_ORDER_OVER_RECEIPT', 'كمية الاستلام تتجاوز الكمية المتبقية من أمر الشراء.');
                }
                // Allocate cumulative value, then subtract earlier receipts. This
                // prevents repeated rounding from making the final receipt negative.
                $cumulativeValue = Decimal::cmp($nextQty, $poLine->quantity) === 0 ? $poLine->taxable_base : (string) Decimal::of($poLine->taxable_base)->multipliedBy($nextQty)->dividedBy($poLine->quantity, 4, RoundingMode::HALF_UP);
                $value = Decimal::sub($cumulativeValue, $allocated['value']);
                $used[$poLine->id] = ['quantity' => $nextQty, 'value' => Decimal::add($allocated['value'], $value)];
            } else {
                $value = Decimal::mul($qty, $raw['unit_cost']);
            }
            $unitCost = $order ? (string) Decimal::of($value)->dividedBy($qty, 8, RoundingMode::HALF_UP) : (string) Decimal::of($raw['unit_cost'])->toScale(8);
            $baseValue = Decimal::mul($value, $rate);
            if (Decimal::cmp($unitCost, '9999999999.99999999') > 0 || Decimal::cmp($value, '99999999999999.9999') > 0 || Decimal::cmp($baseValue, '99999999999999.9999') > 0) {
                throw new BusinessException('AMOUNT_OUT_OF_RANGE', 'قيمة الاستلام تتجاوز الدقة المدعومة.');
            }
            $result[] = ['purchase_order_line_id' => $poLine?->id, 'product_id' => $product->id, 'product_snapshot' => [...$product->only(['id', 'sku', 'name_ar', 'manufacturer_model', 'serial_tracked', 'unit_id']), 'unit_name' => $product->unit->name_ar], 'location_id' => $location->id, 'condition' => $raw['condition'], 'condition_notes' => $raw['condition_notes'] ?? null, 'quantity' => $qty, 'serials' => $serials, 'unit_cost' => $unitCost, 'foreign_value' => $value, 'base_value' => $baseValue];
            $foreignTotal = Decimal::add($foreignTotal, $value);
            $baseTotal = Decimal::add($baseTotal, $baseValue);
        }
        if (Decimal::cmp($foreignTotal, '99999999999999.9999') > 0 || Decimal::cmp($baseTotal, '99999999999999.9999') > 0) {
            throw new BusinessException('AMOUNT_OUT_OF_RANGE', 'إجمالي الاستلام يتجاوز الدقة المدعومة.');
        }

        return ['lines' => $result, 'foreign_total' => $foreignTotal, 'base_total' => $baseTotal];
    }
}
