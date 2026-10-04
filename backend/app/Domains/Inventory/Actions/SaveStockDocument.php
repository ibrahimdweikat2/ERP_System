<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Catalog\Models\Product;
use App\Domains\Inventory\Enums\StockDocumentType;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\SerialNumber;
use App\Domains\Inventory\Models\StockLocation;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Support\BusinessException;
use Illuminate\Support\Facades\DB;

class SaveStockDocument
{
    public function execute(StockDocumentType $type, array $data, int $actorId, ?int $id = null): InventoryDocument
    {
        return DB::transaction(function () use ($type, $data, $actorId, $id) {
            StoreSetting::sharedCurrent();
            $class = $type->model();
            $doc = $id ? $class::with('lines')->lockForUpdate()->findOrFail($id) : new $class;
            if ($id && $doc->status === 'posted') {
                throw new BusinessException('STOCK_DOCUMENT_IMMUTABLE', 'المستند المرحّل ثابت. أنشئ مستند تصحيح جديداً.');
            }
            if ($id && $doc->version !== (int) $data['version']) {
                throw new BusinessException('DOCUMENT_VERSION_CONFLICT', 'تغير المستند. أعد تحميل أحدث نسخة.', 409);
            }
            $before = $id ? $doc->toArray() : null;
            $locations = StockLocation::whereIn('id', array_filter([$data['location_id'], $data['destination_id'] ?? null]))->orderBy('id')->sharedLock()->get();
            if ($locations->contains(fn ($l) => ! $l->active)) {
                throw new BusinessException('STOCK_LOCATION_INACTIVE', 'اختر مواقع نشطة.');
            }
            $products = Product::with('unit')->whereIn('id', array_column($data['lines'], 'product_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $ledger = app(StockLedger::class);
            $snapshots = [];
            if ($id && $type === StockDocumentType::Count) {
                $oldIds = $doc->lines->pluck('product_id')->sort()->values()->all();
                $newIds = array_map('intval', array_column($data['lines'], 'product_id'));
                sort($newIds);
                if ((int) $data['location_id'] !== $doc->location_id || $oldIds !== $newIds) {
                    throw new BusinessException('COUNT_SNAPSHOT_FIXED', 'موقع الجرد ومنتجاته ثابتة بعد بدء اللقطة. أنشئ جرداً جديداً لتغييرها.');
                }
                foreach ($doc->lines as $line) {
                    $snapshots[$line->product_id] = $line->only(['expected_quantity', 'expected_serials', 'snapshot_movement_id']);
                }
            }
            $header = array_intersect_key($data, array_flip(['document_date', 'reason', 'location_id', 'destination_id', 'adjustment_kind']));
            if ($id && $doc->approval_id) {
                $doc->approval()->whereIn('status', ['pending', 'approved', 'rejected'])->update(['status' => 'superseded']);
            }
            $doc->fill([...$header, 'version' => $id ? $doc->version + 1 : 1, 'status' => 'draft', 'approval_id' => null, 'approved_payload_hash' => null, 'gross_value' => '0', 'created_by' => $doc->created_by ?? $actorId, 'updated_by' => $actorId]);
            if ($type === StockDocumentType::Count && ! $id) {
                $doc->snapshot_at = now();
            }
            $doc->save();
            if ($id) {
                $doc->lines()->delete();
            }
            $allSerials = [];
            foreach ($data['lines'] as $line) {
                $product = $products[(int) $line['product_id']] ?? throw new BusinessException('PRODUCT_MISSING', 'أحد المنتجات غير موجود.');
                if (! $product->active) {
                    throw new BusinessException('PRODUCT_INACTIVE', 'لا يمكن تسجيل حركة لمنتج غير نشط.');
                }
                if ($type === StockDocumentType::Count) {
                    $balance = $ledger->balance($product->id, (int) $data['location_id']);
                    $snapshot = $snapshots[$product->id] ?? ['expected_quantity' => $balance->qty_on_hand, 'expected_serials' => SerialNumber::where('product_id', $product->id)->where('current_location_id', $data['location_id'])->orderBy('serial_no')->pluck('serial_no')->all(), 'snapshot_movement_id' => $balance->last_movement_id];
                    $serials = $line['counted_serials'];
                    $counted = $line['counted_quantity'] ?? null;
                    if ($counted !== null) {
                        $ledger->validateSerials($product, $counted, $serials);
                    }
                    $values = [...$snapshot, 'counted_quantity' => $counted, 'counted_serials' => $serials, 'unit_cost' => $line['unit_cost'] ?? null];
                } else {
                    $serials = $line['serials'];
                    $ledger->validateQuantity($product, $line['quantity']);
                    $ledger->validateSerials($product, $line['quantity'], $serials);
                    $values = ['quantity' => $line['quantity'], 'serials' => $serials, 'unit_cost' => $line['unit_cost'] ?? null];
                    if ($type === StockDocumentType::Adjustment && in_array($data['adjustment_kind'], ['opening', 'gain'], true) && $values['unit_cost'] === null) {
                        throw new BusinessException('RECEIPT_COST_REQUIRED', 'أدخل تكلفة الوحدة بالعملة الأساسية.');
                    }
                }
                foreach ($serials as $serial) {
                    if (in_array($serial, $allSerials, true)) {
                        throw new BusinessException('SERIAL_DUPLICATE', 'الرقم التسلسلي مكرر بين سطور المستند.');
                    }$allSerials[] = $serial;
                }
                $doc->lines()->create(['product_id' => $product->id, ...$values]);
            }
            $doc->load(['lines.product', 'location', 'approval']);
            app(RecordAudit::class)->execute($type->value.'.saved', $type->value, $doc->id, $before, $doc->toArray(), $actorId);

            return $doc;
        }, 5);
    }
}
