<?php

namespace Tests\Feature;

use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\Unit;
use App\Domains\Identity\Models\Role;
use App\Domains\Purchasing\Actions\SavePurchaseOrder;
use App\Domains\Purchasing\Actions\SaveSupplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProductSkuTest extends TestCase
{
    use RefreshDatabase;

    private int $brand;

    private int $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $owner = User::factory()->create();
        $owner->roles()->attach(Role::where('name', 'owner')->firstOrFail());
        $this->actingAs($owner);
        $this->brand = DB::table('brands')->insertGetId(['name_ar' => 'سامسونج', 'name_en' => 'Samsung', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->category = DB::table('categories')->insertGetId(['name_ar' => 'غسالات', 'name_en' => 'Washers', 'slug' => 'wm', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function payload(array $overrides = []): array
    {
        return ['name_ar' => 'غسالة', 'unit_id' => Unit::firstOrFail()->id, 'brand_id' => $this->brand, 'category_id' => $this->category, 'serial_tracked' => true, 'active' => true,
            'cash_price' => '100', 'installment_price' => '120', 'minimum_price' => '90', 'reorder_level' => '0', 'barcodes' => [], ...$overrides];
    }

    public function test_suggestion_follows_brand_and_category_and_skips_used_numbers(): void
    {
        $this->getJson("/api/v1/products/sku-suggestion?brand_id={$this->brand}&category_id={$this->category}")->assertOk()->assertJsonPath('data.sku', 'SAM-WM-001');
        $this->postJson('/api/v1/products', $this->payload(['sku' => 'SAM-WM-007']))->assertCreated();
        $this->getJson("/api/v1/products/sku-suggestion?brand_id={$this->brand}&category_id={$this->category}")->assertJsonPath('data.sku', 'SAM-WM-008');
        $this->getJson("/api/v1/products/sku-suggestion?brand_id={$this->brand}")->assertJsonPath('data.sku', 'SAM-001');
        $this->getJson('/api/v1/products/sku-suggestion')->assertJsonPath('data.sku', 'PRD-001');
    }

    public function test_blank_sku_is_assigned_on_create_and_an_edited_sku_is_kept(): void
    {
        $first = $this->postJson('/api/v1/products', $this->payload(['sku' => '']))->assertCreated()->json('data.sku');
        $second = $this->postJson('/api/v1/products', $this->payload())->assertCreated()->json('data.sku');
        $this->assertSame(['SAM-WM-001', 'SAM-WM-002'], [$first, $second]);

        $this->postJson('/api/v1/products', $this->payload(['sku' => 'my-custom-1']))->assertCreated()->assertJsonPath('data.sku', 'MY-CUSTOM-1');
        $this->postJson('/api/v1/products', $this->payload(['sku' => 'SAM-WM-001']))->assertUnprocessable()->assertJsonValidationErrors('sku');
    }

    public function test_an_existing_product_keeps_a_required_sku(): void
    {
        $id = $this->postJson('/api/v1/products', $this->payload())->assertCreated()->json('data.id');
        $this->putJson("/api/v1/products/{$id}", $this->payload(['sku' => '']))->assertUnprocessable()->assertJsonValidationErrors('sku');
        $this->assertSame('SAM-WM-001', Product::findOrFail($id)->sku);
    }

    public function test_suggestion_requires_catalog_management(): void
    {
        $cashier = User::factory()->create();
        $cashier->roles()->attach(Role::where('name', 'cashier')->firstOrFail());
        $this->actingAs($cashier);
        $this->getJson('/api/v1/products/sku-suggestion')->assertForbidden();
    }

    public function test_unused_product_is_deleted_with_its_barcodes_and_image(): void
    {
        Storage::fake('public');
        $id = $this->postJson('/api/v1/products', $this->payload(['barcodes' => ['123456789']]))->assertCreated()->json('data.id');
        $this->post("/api/v1/products/{$id}/image", ['file' => UploadedFile::fake()->image('p.png', 20, 20)])->assertCreated();
        $path = DB::table('product_images')->where('product_id', $id)->value('stored_path');

        $this->deleteJson("/api/v1/products/{$id}")->assertOk();
        $this->assertNull(Product::find($id));
        $this->assertFalse(DB::table('product_barcodes')->where('product_id', $id)->exists());
        $this->assertFalse(Storage::disk('public')->exists($path));
        $this->assertTrue(DB::table('audit_logs')->where('action', 'catalog.product_deleted')->where('entity_id', $id)->exists());
    }

    public function test_product_used_by_a_document_cannot_be_deleted(): void
    {
        $id = $this->postJson('/api/v1/products', $this->payload())->assertCreated()->json('data.id');
        $supplier = app(SaveSupplier::class)->execute(['code' => 'DEL-SUP', 'legal_name' => 'مورد', 'currency' => 'ILS', 'contacts' => [], 'payment_terms_days' => 0, 'credit_limit' => '0', 'active' => true], auth()->id());
        app(SavePurchaseOrder::class)->execute(['supplier_id' => $supplier->id, 'document_date' => '2026-08-01', 'currency' => 'ILS', 'lines' => [['product_id' => $id, 'quantity' => '1', 'unit_price' => '50', 'discount_amount' => '0', 'tax_code_id' => null, 'tax_inclusive' => false]]], auth()->id());

        $this->deleteJson("/api/v1/products/{$id}")->assertStatus(409)->assertJsonPath('code', 'PRODUCT_IN_USE');
        $this->assertNotNull(Product::find($id));
    }

    public function test_deleting_requires_catalog_management(): void
    {
        $id = $this->postJson('/api/v1/products', $this->payload())->assertCreated()->json('data.id');
        $cashier = User::factory()->create();
        $cashier->roles()->attach(Role::where('name', 'cashier')->firstOrFail());
        $this->actingAs($cashier);
        $this->deleteJson("/api/v1/products/{$id}")->assertForbidden();
        $this->assertNotNull(Product::find($id));
    }
}
