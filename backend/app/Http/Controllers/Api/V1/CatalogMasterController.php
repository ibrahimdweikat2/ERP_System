<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Catalog\Models\Brand;
use App\Domains\Catalog\Models\Category;
use App\Domains\Catalog\Models\Unit;
use App\Domains\Catalog\Models\WarrantyPolicy;
use App\Domains\Inventory\Models\StockLocation;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Http\Controllers\Controller;
use App\Support\BusinessException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class CatalogMasterController extends Controller
{
    public const MODELS = ['categories' => Category::class, 'brands' => Brand::class, 'units' => Unit::class, 'warranty-policies' => WarrantyPolicy::class, 'stock-locations' => StockLocation::class];

    public function index(Request $r): JsonResponse
    {
        $kind = $r->route('master');
        $class = self::MODELS[$kind];
        $d = $r->validate(['search' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'between:1,100']]);

        return response()->json($class::when($d['search'] ?? null, fn ($q, $s) => $q->where('name_ar', 'like', "%$s%"))->orderBy('id')->paginate(\App\Support\PerPage::resolve(50)));
    }

    public function store(Request $r): JsonResponse
    {
        return $this->save($r);
    }

    public function update(Request $r): JsonResponse
    {
        return $this->save($r, $r->route('id'));
    }

    private function save(Request $r, ?string $id = null): JsonResponse
    {
        $kind = $r->route('master');
        $class = self::MODELS[$kind];
        $table = (new $class)->getTable();
        $rules = ['name_ar' => ['required', 'string', 'max:120'], 'name_en' => ['nullable', 'string', 'max:120'], 'active' => ['required', 'boolean']];
        if ($kind === 'categories') {
            $rules = [...$rules, 'parent_id' => ['nullable', 'integer', 'exists:categories,id'], 'slug' => ['required', 'regex:/^[a-z0-9_-]{1,160}$/', Rule::unique($table)->ignore($id)], 'sort_order' => ['required', 'integer', 'min:0']];
        }
        if ($kind === 'units') {
            $rules = ['name_ar' => ['required', 'string', 'max:80'], 'code' => ['required', 'regex:/^[a-z0-9_-]{1,20}$/', Rule::unique($table)->ignore($id)], 'decimal_places' => ['required', 'integer', 'between:0,4']];
        }
        if ($kind === 'warranty-policies') {
            $rules = ['name_ar' => ['required', 'string', 'max:120'], 'duration_value' => ['required', 'integer', 'between:1,3650'], 'duration_unit' => ['required', Rule::in(['day', 'month', 'year'])], 'provider_type' => ['required', Rule::in(['store', 'supplier', 'manufacturer'])], 'terms' => ['nullable', 'string', 'max:5000'], 'active' => ['required', 'boolean']];
        }
        if ($kind === 'stock-locations') {
            $rules = ['code' => ['required', 'regex:/^[A-Za-z0-9_-]{1,30}$/', Rule::unique($table)->ignore($id)], 'name_ar' => ['required', 'string', 'max:120'], 'purpose' => ['required', Rule::in(['showroom', 'warehouse', 'reserved', 'returns', 'damaged', 'warranty'])], 'sellable' => ['required', 'boolean'], 'active' => ['required', 'boolean']];
        }
        $data = $r->validate($rules);

        return DB::transaction(function () use ($kind, $class, $id, $data) {
            StoreSetting::lockForUpdate()->findOrFail(1);
            $record = $id ? $class::lockForUpdate()->findOrFail($id) : new $class;
            $before = $record->exists ? $record->toArray() : null;
            if ($kind === 'units' && $id && (int) $record->decimal_places !== (int) $data['decimal_places'] && Schema::hasTable('inventory_movements') && DB::table('inventory_movements')->join('products', 'products.id', '=', 'inventory_movements.product_id')->where('products.unit_id', $id)->exists()) {
                throw new BusinessException('UNIT_PRECISION_IN_USE', 'لا يمكن تغيير دقة وحدة مستخدمة في حركات مخزون.');
            }
            if ($kind === 'categories') {
                $parent = $data['parent_id'] ?? null;
                $seen = $id ? [(int) $id] : [];
                while ($parent) {
                    if (in_array($parent, $seen, true)) {
                        throw new BusinessException('CATEGORY_CYCLE', 'التصنيف الأب لا يمكن أن يكون من فروع التصنيف نفسه.');
                    }$seen[] = $parent;
                    $parent = Category::findOrFail($parent)->parent_id;
                }
            }
            if ($kind === 'stock-locations') {
                if (in_array($data['purpose'], ['reserved', 'returns', 'damaged', 'warranty']) && $data['sellable']) {
                    throw new BusinessException('LOCATION_NOT_SELLABLE', 'مواقع الحجز والفحص والتالف والصيانة غير متاحة للبيع المباشر.');
                }
                if ($id && Schema::hasTable('inventory_balances') && DB::table('inventory_balances')->where('location_id', $id)->where('qty_on_hand', '>', 0)->exists() && (! $data['active'] || $record->purpose !== $data['purpose'] || $record->sellable !== $data['sellable'])) {
                    throw new BusinessException('LOCATION_HAS_STOCK', 'انقل المخزون قبل تغيير وظيفة الموقع أو تعطيله.');
                }
            }
            $record->fill($data)->save();
            app(RecordAudit::class)->execute('catalog.master_saved', $kind, $record->id, $before, $record->toArray());

            return response()->json(['data' => $record], $id ? 200 : 201);
        }, 3);
    }
}
