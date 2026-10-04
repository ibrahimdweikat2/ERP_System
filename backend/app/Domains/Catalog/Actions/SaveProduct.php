<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Catalog\Models\Product;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Support\BusinessException;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SaveProduct
{
    public function execute(array $data, int $actorId, ?int $id = null): Product
    {
        return DB::transaction(function () use ($data, $actorId, $id) {
            StoreSetting::lockCurrent();
            $product = $id ? Product::with('barcodes')->lockForUpdate()->findOrFail($id) : new Product;
            if (Decimal::cmp($data['minimum_price'], $data['cash_price']) > 0 || Decimal::cmp($data['minimum_price'], $data['installment_price']) > 0) {
                throw new BusinessException('PRODUCT_PRICE_BELOW_MINIMUM', 'أسعار البيع يجب ألا تقل عن الحد الأدنى.');
            }
            if ($id && Schema::hasTable('inventory_movements') && DB::table('inventory_movements')->where('product_id', $id)->exists()) {
                if ($product->serial_tracked !== (bool) $data['serial_tracked'] || $product->unit_id !== (int) $data['unit_id']) {
                    throw new BusinessException('PRODUCT_TRACKING_IN_USE', 'لا يمكن تغيير وحدة القياس أو تتبع الأرقام بعد تسجيل حركات مخزون.');
                }
            }
            // Assigned under the exclusive store lock above, so concurrent creations
            // can never receive the same number.
            if (! $product->exists && empty($data['sku'])) {
                $data['sku'] = app(SuggestSku::class)->execute($data['brand_id'] ?? null, $data['category_id'] ?? null);
            }
            $before = $product->exists ? $product->toArray() : null;
            $barcodes = $data['barcodes'];
            unset($data['barcodes']);
            $product->fill([...$data, 'created_by' => $product->created_by ?? $actorId, 'updated_by' => $actorId])->save();
            $product->barcodes()->whereNotIn('barcode', $barcodes)->delete();
            foreach ($barcodes as $barcode) {
                $product->barcodes()->firstOrCreate(['barcode' => $barcode]);
            }
            $product->load(['barcodes', 'brand', 'category', 'unit', 'warrantyPolicy', 'taxCode']);
            app(RecordAudit::class)->execute($id ? 'catalog.product_updated' : 'catalog.product_created', 'product', $product->id, $before, $product->toArray(), $actorId);

            return $product;
        }, 3);
    }
}
