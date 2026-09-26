<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Catalog\Models\Product;
use App\Domains\Inventory\Models\InventoryBalance;
use App\Domains\Inventory\Models\InventoryMovement;
use App\Domains\Inventory\Models\SerialNumber;
use App\Domains\Inventory\Models\StockLocation;
use App\Support\BusinessException;
use App\Support\Decimal;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

class StockLedger
{
    public static function normalizeSerial(string $value): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', '', trim($value)));
    }

    public function balance(int $productId, int $locationId): InventoryBalance
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Stock actions require a transaction.');
        }
        Product::whereKey($productId)->lockForUpdate()->firstOrFail();

        // A locking read must follow the product lock. A repeatable-read snapshot
        // can predate a competing post even after the caller obtains that lock.
        $key = ['product_id' => $productId, 'location_id' => $locationId];
        $balance = InventoryBalance::where($key)->lockForUpdate()->first();
        if (! $balance) {
            InventoryBalance::create([...$key, 'updated_at' => now()]);
            $balance = InventoryBalance::where($key)->lockForUpdate()->firstOrFail();
        }

        return $balance;
    }

    public function validateQuantity(Product $product, string $quantity, bool $allowZero = false): void
    {
        $d = Decimal::of($quantity);
        if ($d->isNegative() || (! $allowZero && $d->isZero()) || $d->compareTo('99999999999999.9999') > 0) {
            throw new BusinessException('INVALID_STOCK_QUANTITY', 'الكمية يجب أن تكون موجبة وضمن الحد المسموح.');
        }
        $places = $product->serial_tracked ? 0 : $product->unit->decimal_places;
        try {
            $d->toScale($places);
        } catch (RoundingNecessaryException) {
            throw new BusinessException('QUANTITY_PRECISION', 'الكمية لا تطابق دقة الوحدة أو عدد الأجهزة المسلسلة.');
        }
    }

    public function validateSerials(Product $product, string $quantity, array $serials): void
    {
        $this->validateQuantity($product, $quantity, true);
        if (! $product->serial_tracked && count($serials)) {
            throw new BusinessException('SERIALS_NOT_EXPECTED', 'هذا المنتج يتتبع الكمية دون أرقام تسلسلية.');
        }
        if ($product->serial_tracked && Decimal::cmp($quantity, (string) count($serials)) !== 0) {
            throw new BusinessException('SERIAL_COUNT_MISMATCH', 'عدد الأرقام التسلسلية يجب أن يساوي كمية المنتج.');
        }
        foreach ($serials as $s) {
            if (! is_string($s) || $s !== self::normalizeSerial($s) || ! preg_match('/^[A-Z0-9][A-Z0-9_.\/-]{0,119}$/', $s)) {
                throw new BusinessException('SERIAL_INVALID', 'أدخل أرقاماً تسلسلية صحيحة.');
            }
        }
        if (count(array_unique($serials)) !== count($serials)) {
            throw new BusinessException('SERIAL_DUPLICATE', 'لا يمكن تكرار رقم تسلسلي في المستند.');
        }
    }

    public function issueCost(InventoryBalance $balance, string $quantity): string
    {
        if (Decimal::cmp($quantity, $balance->qty_available) > 0) {
            throw new BusinessException('INSUFFICIENT_STOCK', 'الكمية المطلوبة تتجاوز المخزون غير المحجوز في الموقع.');
        }
        if (Decimal::cmp($quantity, $balance->qty_on_hand) === 0) {
            return $balance->inventory_value;
        }

        return Decimal::money(Decimal::of($balance->inventory_value)->multipliedBy($quantity)->dividedBy($balance->qty_on_hand, 4, RoundingMode::HALF_UP));
    }

    public function apply(Product $product, StockLocation $location, string $direction, string $quantity, ?string $incomingValue, array $serials, array $source, int $actorId, ?StockLocation $transferDestination = null, bool $transferReceipt = false): InventoryMovement
    {
        $this->validateSerials($product, $quantity, $serials);
        $this->validateQuantity($product, $quantity);
        if (! $product->active || ! $location->active) {
            throw new BusinessException('STOCK_MASTER_INACTIVE', 'المنتج أو الموقع غير نشط.');
        }
        $balance = $this->balance($product->id, $location->id);
        $lastDate = InventoryMovement::where('product_id', $product->id)->where('location_id', $location->id)->orderByDesc('movement_date')->orderByDesc('id')->lockForUpdate()->first()?->movement_date;
        if ($lastDate && $source['date'] < $lastDate) {
            throw new BusinessException('STOCK_BACKDATE_CONFLICT', 'لا يمكن تأريخ حركة قبل آخر حركة للمنتج في هذا الموقع.');
        }
        if ($direction === 'out') {
            $value = $this->issueCost($balance, $quantity);
            $qty = Decimal::sub($balance->qty_on_hand, $quantity);
            $remaining = Decimal::sub($balance->inventory_value, $value);
        } else {
            $value = $incomingValue ?? throw new \LogicException('Receipt value required.');
            $qty = Decimal::add($balance->qty_on_hand, $quantity);
            $remaining = Decimal::add($balance->inventory_value, $value);
        }
        if (Decimal::cmp($value, '0') < 0 || Decimal::cmp($remaining, '99999999999999.9999') > 0 || Decimal::cmp($qty, '99999999999999.9999') > 0) {
            throw new BusinessException('STOCK_VALUE_OUT_OF_RANGE', 'قيمة أو كمية المخزون تتجاوز الدقة المسموحة.');
        }
        $average = Decimal::cmp($qty, '0') === 0 ? '0.00000000' : (string) Decimal::of($remaining)->dividedBy($qty, 8, RoundingMode::HALF_UP);
        $unitCost = (string) Decimal::of($value)->dividedBy($quantity, 8, RoundingMode::HALF_UP);
        if (Decimal::cmp($average, '9999999999.99999999') > 0 || Decimal::cmp($unitCost, '9999999999.99999999') > 0) {
            throw new BusinessException('UNIT_COST_OUT_OF_RANGE', 'تكلفة الوحدة تتجاوز الحد المسموح.');
        }
        $movement = InventoryMovement::create(['product_id' => $product->id, 'location_id' => $location->id, 'direction' => $direction, 'quantity' => $quantity, 'unit_cost' => $unitCost, 'total_cost' => $value, 'movement_type' => $source['movement_type'], 'source_type' => $source['type'], 'source_id' => $source['id'], 'source_line_id' => $source['line_id'], 'source_event' => $source['event'], 'movement_date' => $source['date'], 'occurred_at' => $source['date'].' 12:00:00', 'posted_at' => now(), 'posted_by' => $actorId]);
        foreach ($serials as $number) {
            $serial = SerialNumber::where('serial_no', $number)->lockForUpdate()->first();
            if ($direction === 'in' && $source['movement_type'] === 'sales_return') {
                if (!$serial || $serial->product_id !== $product->id || $serial->status !== 'sold' || $serial->sold_sales_line_id !== ($source['original_line_id'] ?? null)) {
                    throw new BusinessException('RETURN_SERIAL_MISMATCH', 'الجهاز لا يطابق السطر المباع أو أعيد سابقاً: '.$number);
                }
                $fromStatus = $serial->status;
                $fromLocation = $serial->current_location_id;
                $serial->update(['status' => $this->locationStatus($location), 'current_location_id' => $location->id]);
            } elseif ($direction === 'in' && ! $transferReceipt) {
                if ($serial) {
                    throw new BusinessException('SERIAL_ALREADY_EXISTS', 'الرقم التسلسلي مسجل سابقاً: '.$number);
                }
                $serial = SerialNumber::create(['product_id' => $product->id, 'serial_no' => $number, 'status' => $this->locationStatus($location), 'current_location_id' => $location->id, 'acquisition_cost' => Decimal::money($unitCost), 'receipt_movement_id' => $movement->id]);
                $fromStatus = null;
                $fromLocation = null;
            } elseif ($transferReceipt) {
                if (! $serial || $serial->product_id !== $product->id || $serial->current_location_id !== $location->id) {
                    throw new BusinessException('TRANSFER_SERIAL_MISMATCH', 'تعذر مطابقة الرقم مع التحويل.');
                }

                continue;
            } else {
                if (! $serial || $serial->product_id !== $product->id || $serial->current_location_id !== $location->id || ! in_array($serial->status, ['in_stock', 'returned_pending_inspection', 'damaged', 'warranty_service', 'reserved'], true)) {
                    throw new BusinessException('SERIAL_NOT_AVAILABLE', 'الرقم التسلسلي غير متاح في الموقع: '.$number);
                }
                if ($serial->status === 'reserved' && $location->purpose !== 'reserved') {
                    throw new BusinessException('SERIAL_RESERVED', 'لا يمكن صرف رقم تسلسلي محجوز لعميل.');
                }
                $fromStatus = $serial->status;
                $fromLocation = $serial->current_location_id;
                if ($source['movement_type'] === 'sale_issue' && ($serial->status !== 'in_stock' || !$location->sellable)) {
                    throw new BusinessException('SERIAL_NOT_SELLABLE', 'الجهاز غير صالح للبيع: '.$number);
                }
                $status = $transferDestination ? $this->locationStatus($transferDestination) : match($source['movement_type']) { 'sale_issue' => 'sold', 'purchase_return' => 'supplier_returned', default => 'retired' };
                $attributes = ['status' => $status, 'current_location_id' => $transferDestination?->id];
                if ($source['movement_type'] === 'sale_issue') $attributes += ['sold_sales_line_id' => $source['line_id'], 'customer_id' => $source['customer_id'], 'warranty_start' => $source['date'], 'warranty_end' => $source['warranty_end'] ?? null];
                $serial->update($attributes);
            }
            DB::table('serial_movements')->insert(['serial_number_id' => $serial->id, 'inventory_movement_id' => $movement->id, 'from_status' => $fromStatus, 'to_status' => $serial->status, 'from_location_id' => $fromLocation, 'to_location_id' => $serial->current_location_id, 'actor_id' => $actorId, 'occurred_at' => now()]);
        }
        $balance->update(['qty_on_hand' => $qty, 'inventory_value' => $remaining, 'average_cost' => $average, 'last_movement_id' => $movement->id, 'updated_at' => now()]);

        return $movement;
    }

    public function locationStatus(StockLocation $location): string
    {
        return match ($location->purpose) {
            'returns' => 'returned_pending_inspection','damaged' => 'damaged','warranty' => 'warranty_service','reserved' => 'reserved',default => 'in_stock'
        };
    }
}
