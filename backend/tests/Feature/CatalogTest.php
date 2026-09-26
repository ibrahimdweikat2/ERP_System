<?php

namespace Tests\Feature;

use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\Unit;
use App\Domains\Identity\Models\Permission;
use App\Domains\Identity\Models\Role;
use App\Domains\StoreSetup\Actions\SaveStoreSettings;
use App\Models\User;
use App\Support\BusinessException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->loginRole();
    }

    private function loginRole(string $role = 'owner'): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('name', $role)->firstOrFail());
        $this->actingAs($u);

        return $u;
    }

    private function product(array $overrides = []): array
    {
        return [...['sku' => ' wm-100 ', 'name_ar' => 'غسالة اختبار', 'unit_id' => Unit::firstOrFail()->id, 'serial_tracked' => true, 'active' => true, 'standard_cost' => '100.0001', 'cash_price' => '150.0001', 'installment_price' => '180', 'minimum_price' => '120', 'reorder_level' => '2', 'barcodes' => [' abc-123 '], 'specifications' => [['name' => 'السعة', 'value' => '8 كيلو']]], ...$overrides];
    }

    public function test_catalog_normalizes_unique_identifiers_and_preserves_exact_prices(): void
    {
        $id = $this->postJson('/api/v1/products', $this->product())->assertCreated()->assertJsonPath('data.sku', 'WM-100')->assertJsonPath('data.standard_cost', '100.0001')->assertJsonPath('data.barcodes.0.barcode', 'ABC-123')->json('data.id');
        $this->getJson('/api/v1/products?search=abc-123')->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson('/api/v1/products', $this->product(['sku' => 'OTHER']))->assertUnprocessable()->assertJsonValidationErrors('barcodes.0');
        $this->putJson('/api/v1/products/'.$id, $this->product(['cash_price' => '151.2300']))->assertOk()->assertJsonPath('data.cash_price', '151.2300');
        $this->postJson('/api/v1/products', $this->product(['barcodes' => []]))->assertUnprocessable()->assertJsonValidationErrors('sku');
    }

    public function test_quantity_and_float_prices_are_rejected(): void
    {
        $this->postJson('/api/v1/products', $this->product(['quantity' => '5']))->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->postJson('/api/v1/products', $this->product(['cash_price' => 150.1]))->assertUnprocessable()->assertJsonValidationErrors('cash_price');
        $this->postJson('/api/v1/products', $this->product(['cash_price' => '119']))->assertUnprocessable()->assertJsonPath('code', 'PRODUCT_PRICE_BELOW_MINIMUM');
        $this->assertDatabaseCount('products', 0);
    }

    public function test_cost_visibility_and_catalog_write_permission_are_enforced(): void
    {
        $id = $this->postJson('/api/v1/products', $this->product())->assertCreated()->json('data.id');
        $this->loginRole('cashier');
        $this->getJson('/api/v1/products/'.$id)->assertOk()->assertJsonMissingPath('data.standard_cost');
        $this->getJson('/api/v1/products')->assertOk()->assertJsonMissingPath('data.0.standard_cost');
        $this->postJson('/api/v1/products', $this->product())->assertForbidden();
        $role = Role::create(['name' => 'catalog_clerk', 'label' => 'Catalog clerk']);
        $role->permissions()->attach(Permission::whereIn('name', ['catalog.view', 'catalog.manage'])->pluck('id'));
        $u = User::factory()->create();
        $u->roles()->attach($role);
        $this->actingAs($u);
        $this->putJson('/api/v1/products/'.$id, $this->product())->assertUnprocessable()->assertJsonValidationErrors('standard_cost');
        $data = $this->product();
        unset($data['standard_cost']);
        $this->putJson('/api/v1/products/'.$id, $data)->assertOk()->assertJsonMissingPath('data.standard_cost');
        $this->assertSame('100.0001', Product::findOrFail($id)->standard_cost);
        $this->getJson('/api/v1/catalog/tax-options')->assertOk();
    }

    public function test_categories_reject_cycles_and_quarantine_locations_reject_sales(): void
    {
        $data = ['name_ar' => 'أجهزة', 'slug' => 'appliances', 'sort_order' => 0, 'active' => true];
        $parent = $this->postJson('/api/v1/categories', $data)->assertCreated()->json('data.id');
        $child = $this->postJson('/api/v1/categories', [...$data, 'slug' => 'washing', 'parent_id' => $parent])->assertCreated()->json('data.id');
        $this->putJson('/api/v1/categories/'.$parent, [...$data, 'parent_id' => $child])->assertUnprocessable()->assertJsonPath('code', 'CATEGORY_CYCLE');
        $this->postJson('/api/v1/stock-locations', ['code' => 'DAMAGED2', 'name_ar' => 'تالف', 'purpose' => 'damaged', 'sellable' => true, 'active' => true])->assertUnprocessable()->assertJsonPath('code', 'LOCATION_NOT_SELLABLE');
    }

    public function test_base_currency_cannot_reinterpret_existing_catalog_prices(): void
    {
        $this->postJson('/api/v1/products', $this->product())->assertCreated();
        $this->expectException(BusinessException::class);
        app(SaveStoreSettings::class)->execute(['base_currency' => 'USD']);
    }
}
