<?php

namespace Tests\Feature;

use App\Domains\Catalog\Models\Product;
use App\Domains\Identity\Models\Role;
use App\Domains\Purchasing\Actions\SavePurchaseOrder;
use App\Domains\Purchasing\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $owner = User::factory()->create();
        $owner->roles()->attach(Role::where('name', 'owner')->firstOrFail());
        $this->actingAs($owner);
    }

    private function supplier(string $code = 'DEL-SUP'): int
    {
        return $this->withHeader('Idempotency-Key', 'supplier-'.$code)->postJson('/api/v1/suppliers', ['code' => $code, 'legal_name' => 'مورد للحذف', 'currency' => 'ILS',
            'contacts' => [], 'payment_terms_days' => 0, 'credit_limit' => '0', 'active' => true])->assertCreated()->json('data.id');
    }

    public function test_unused_supplier_is_deleted(): void
    {
        $id = $this->supplier();

        $this->deleteJson("/api/v1/suppliers/{$id}")->assertOk();

        $this->assertNull(Supplier::find($id));
        $this->assertTrue(DB::table('audit_logs')->where('action', 'purchasing.supplier_deleted')->where('entity_id', $id)->exists());
    }

    public function test_supplier_used_by_a_purchase_order_is_kept(): void
    {
        $id = $this->supplier();
        $product = Product::create(['sku' => 'DEL-P', 'name_ar' => 'منتج', 'unit_id' => DB::table('units')->value('id'), 'serial_tracked' => false, 'active' => true,
            'cash_price' => '10', 'installment_price' => '12', 'minimum_price' => '9', 'reorder_level' => '0', 'created_by' => auth()->id(), 'updated_by' => auth()->id()]);
        app(SavePurchaseOrder::class)->execute(['supplier_id' => $id, 'document_date' => '2026-08-01', 'currency' => 'ILS', 'lines' => [['product_id' => $product->id, 'quantity' => '1', 'unit_price' => '5', 'discount_amount' => '0', 'tax_code_id' => null, 'tax_inclusive' => false]]], auth()->id());

        $this->deleteJson("/api/v1/suppliers/{$id}")->assertStatus(409)->assertJsonPath('code', 'SUPPLIER_IN_USE');
        $this->assertNotNull(Supplier::find($id));
    }

    public function test_deleting_requires_the_delete_permission(): void
    {
        $id = $this->supplier();
        $clerk = User::factory()->create();
        $clerk->roles()->attach(Role::where('name', 'inventory')->firstOrFail());
        $this->actingAs($clerk);

        $this->deleteJson("/api/v1/suppliers/{$id}")->assertForbidden();
        $this->assertNotNull(Supplier::find($id));
    }
}
