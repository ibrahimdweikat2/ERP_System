<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Catalog\Models\Product;
use App\Domains\Inventory\Enums\StockDocumentType;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\InventoryMovement;
use App\Domains\Inventory\Models\SerialNumber;
use App\Domains\Inventory\Models\StockLocation;
use App\Support\BusinessException;
use App\Support\Decimal;

class PreviewStockDocument
{
    public function execute(StockDocumentType $type, InventoryDocument $doc): array
    {
        $ledger = app(StockLedger::class);
        $doc->load('lines');
        $effects = [];
        $gross = '0.0000';
        $locations = StockLocation::whereIn('id', array_filter([$doc->location_id, $doc->destination_id]))->orderBy('id')->sharedLock()->get()->keyBy('id');
        if ($locations->contains(fn ($l) => ! $l->active)) {
            throw new BusinessException('STOCK_LOCATION_INACTIVE', 'الموقع غير نشط.');
        }
        $products = Product::with('unit')->whereIn('id', $doc->lines->pluck('product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($doc->lines->sortBy('product_id') as $line) {
            $p = $products[$line->product_id];
            if (! $p->active) {
                throw new BusinessException('PRODUCT_INACTIVE', 'المنتج غير نشط.');
            }
            $balance = $ledger->balance($p->id, $doc->location_id);
            $lineEffects = [];
            $add = function (string $direction, string $qty, string $value, array $serials, string $event, int $locationId) use (&$lineEffects, $line, $p) {
                if (Decimal::cmp($qty, '0') > 0) {
                    $lineEffects[] = ['line_id' => $line->id, 'product_id' => $p->id, 'location_id' => $locationId, 'direction' => $direction, 'quantity' => $qty, 'value' => $value, 'serials' => $serials, 'event' => $event];
                }
            };
            if ($type === StockDocumentType::Count) {
                if ($balance->last_movement_id !== $line->snapshot_movement_id) {
                    throw new BusinessException('COUNT_SNAPSHOT_STALE', 'تحرك المخزون منذ بدء الجرد. ابدأ لقطة جرد جديدة قبل الترحيل.');
                }
                if ($line->counted_quantity === null) {
                    throw new BusinessException('COUNT_INCOMPLETE', 'أدخل الكمية الفعلية لكل سطر قبل إرسال الجرد.');
                }
                $ledger->validateSerials($p, $line->counted_quantity, $line->counted_serials);
                if ($p->serial_tracked) {
                    $missing = array_values(array_diff($line->expected_serials, $line->counted_serials));
                    $extra = array_values(array_diff($line->counted_serials, $line->expected_serials));
                    $loss = (string) count($missing);
                    $gain = (string) count($extra);
                } else {
                    $missing = $extra = [];
                    $variance = Decimal::sub($line->counted_quantity, $line->expected_quantity);
                    $loss = Decimal::cmp($variance, '0') < 0 ? Decimal::money(Decimal::of($variance)->abs()) : '0';
                    $gain = Decimal::cmp($variance, '0') > 0 ? $variance : '0';
                }
                if (Decimal::cmp($loss, '0') > 0) {
                    $add('out', $loss, $ledger->issueCost($balance, $loss), $missing, 'loss', $doc->location_id);
                }
                if (Decimal::cmp($gain, '0') > 0) {
                    $cost = $line->unit_cost;
                    if ($cost === null && Decimal::cmp($balance->qty_on_hand, '0') === 0) {
                        throw new BusinessException('COUNT_GAIN_COST_REQUIRED', 'الزيادة على رصيد صفري تحتاج تكلفة وحدة يراجعها صاحب صلاحية التكلفة.');
                    }
                    $cost ??= Decimal::money($balance->average_cost);
                    $add('in', $gain, Decimal::mul($gain, $cost), $extra, 'gain', $doc->location_id);
                }
            } else {
                $ledger->validateSerials($p, $line->quantity, $line->serials);
                if ($type === StockDocumentType::Transfer) {
                    $value = $ledger->issueCost($balance, $line->quantity);
                    $add('out', $line->quantity, $value, $line->serials, 'transfer_out', $doc->location_id);
                    $add('in', $line->quantity, $value, $line->serials, 'transfer_in', $doc->destination_id);
                } elseif (in_array($doc->adjustment_kind, ['opening', 'gain'], true)) {
                    if ($doc->adjustment_kind === 'opening' && InventoryMovement::where('product_id', $p->id)->where('movement_type', '!=', 'opening_balance')->lockForUpdate()->first(['id'])) {
                        throw new BusinessException('OPENING_STOCK_ALREADY_USED', 'لا يمكن إضافة افتتاحي لمنتج بدأت حركاته التشغيلية. استخدم تسوية معتمدة.');
                    }
                    $add('in', $line->quantity, Decimal::mul($line->quantity, $line->unit_cost), $line->serials, 'gain', $doc->location_id);
                } else {
                    $add('out', $line->quantity, $ledger->issueCost($balance, $line->quantity), $line->serials, 'loss', $doc->location_id);
                }
            }
            foreach ($lineEffects as $effect) {
                if (Decimal::cmp($effect['value'], '99999999999999.9999') > 0) {
                    throw new BusinessException('STOCK_VALUE_OUT_OF_RANGE', 'قيمة المستند تتجاوز الحد المسموح.');
                }
                if ($effect['direction'] === 'in' && $type !== StockDocumentType::Transfer && SerialNumber::whereIn('serial_no', $effect['serials'])->exists()) {
                    throw new BusinessException('SERIAL_ALREADY_EXISTS', 'أحد الأرقام المضافة مسجل سابقاً. استخدم مسار الإرجاع أو التحويل الصحيح.');
                }
                $gross = Decimal::add($gross, $effect['value']);
                $effects[] = $effect;
            }
        }
        if (Decimal::cmp($gross, '99999999999999.9999') > 0) {
            throw new BusinessException('STOCK_VALUE_OUT_OF_RANGE', 'إجمالي قيمة الحركات يتجاوز الحد المسموح.');
        }
        $payload = ['type' => $type->value, 'id' => $doc->id, 'version' => $doc->version, 'document_date' => $doc->document_date, 'reason' => $doc->reason, 'location_id' => $doc->location_id, 'destination_id' => $doc->destination_id, 'adjustment_kind' => $doc->adjustment_kind, 'lines' => $doc->lines->sortBy('product_id')->values()->map(fn ($l) => $l->attributesToArray())->all(), 'effects' => $effects];

        return ['effects' => $effects, 'gross_value' => $gross, 'payload' => $payload, 'hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))];
    }
}
