<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductImage;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Support\BusinessException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes a product that no document or stock record has ever used. A used product
 * keeps its history and is deactivated instead.
 */
class DeleteProduct
{
    /** Owned by the product itself and removed with it. */
    private const OWNED = ['product_barcodes', 'product_images'];

    private const LABELS = [
        'purchase_order_lines' => 'أوامر شراء', 'goods_receipt_lines' => 'استلام بضاعة', 'supplier_invoice_lines' => 'فواتير موردين',
        'sales_invoice_lines' => 'فواتير بيع', 'inventory_movements' => 'حركات مخزون', 'inventory_balances' => 'أرصدة مخزون',
        'serial_numbers' => 'أرقام تسلسلية', 'stock_transfer_lines' => 'تحويلات مخزون', 'stock_adjustment_lines' => 'تسويات مخزون',
        'stock_count_lines' => 'جرد مخزون',
    ];

    public function execute(int $id, int $actorId): void
    {
        $imagePath = DB::transaction(function () use ($id, $actorId) {
            // Same exclusive lock as SaveProduct and stock postings: nothing can start
            // using the product between the check and the delete.
            StoreSetting::lockCurrent();
            $product = Product::with('barcodes')->lockForUpdate()->findOrFail($id);
            if ($used = $this->usedBy($id)) {
                throw new BusinessException('PRODUCT_IN_USE', 'لا يمكن حذف منتج مستخدم في مستندات أو حركات مخزون ('.implode('، ', $used).'). عطّله بدلاً من ذلك بإلغاء «منتج نشط».', 409);
            }
            $before = [...$product->toArray(), 'barcodes' => $product->barcodes->pluck('barcode')->all()];
            $image = ProductImage::where('product_id', $id)->first();
            $product->barcodes()->delete();
            $image?->delete();
            $product->delete();
            app(RecordAudit::class)->execute('catalog.product_deleted', 'product', $id, $before, [], $actorId);

            return $image?->stored_path;
        }, 3);
        if ($imagePath) {
            Storage::disk('public')->delete($imagePath);
        }
    }

    /** Tables that reference this product, read from the schema so new modules are covered too. */
    private function usedBy(int $id): array
    {
        $tables = DB::table('information_schema.key_column_usage')
            ->where('table_schema', DB::raw('database()'))->where('referenced_table_name', 'products')
            ->whereNotIn('table_name', self::OWNED)->get(['table_name', 'column_name']);
        $used = [];
        foreach ($tables as $t) {
            $table = $t->table_name ?? $t->TABLE_NAME;
            $column = $t->column_name ?? $t->COLUMN_NAME;
            if (DB::table($table)->where($column, $id)->exists()) {
                $used[] = self::LABELS[$table] ?? 'سجلات أخرى';
            }
        }

        return array_values(array_unique($used));
    }
}
