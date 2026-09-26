<?php

namespace Tests\Feature;

use App\Domains\Customers\Models\Customer;
use App\Domains\Identity\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerDeleteTest extends TestCase
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

    private function customer(string $code = 'DEL-1'): int
    {
        return $this->withHeader('Idempotency-Key', 'customer-'.$code)->postJson('/api/v1/customers', ['code' => $code, 'name' => 'عميل للحذف', 'currency' => 'ILS', 'credit_limit' => '0',
            'max_active_contracts' => 1, 'max_overdue_days' => 0, 'risk_flag' => 'normal', 'is_walk_in' => false, 'active' => true])->assertCreated()->json('data.id');
    }

    public function test_unused_customer_is_deleted_with_followups(): void
    {
        $id = $this->customer();
        $this->postJson("/api/v1/customers/{$id}/followups", ['note' => 'اتصال'])->assertOk();

        $this->deleteJson("/api/v1/customers/{$id}")->assertOk();

        $this->assertNull(Customer::find($id));
        $this->assertFalse(DB::table('customer_followups')->where('customer_id', $id)->exists());
        $this->assertTrue(DB::table('audit_logs')->where('action', 'customers.deleted')->where('entity_id', $id)->exists());
    }

    public function test_customer_with_attachments_is_kept(): void
    {
        $id = $this->customer();
        DB::table('document_attachments')->insert(['entity_type' => 'customer', 'entity_id' => $id, 'original_name' => 'id.pdf', 'stored_path' => 'x/id.pdf', 'mime_type' => 'application/pdf',
            'size' => 10, 'checksum' => str_repeat('a', 64), 'visibility_classification' => 'internal', 'uploaded_by' => auth()->id(), 'created_at' => now()]);

        $this->deleteJson("/api/v1/customers/{$id}")->assertStatus(409)->assertJsonPath('code', 'CUSTOMER_IN_USE');
        $this->assertNotNull(Customer::find($id));
    }

    public function test_deleting_requires_customer_management(): void
    {
        $id = $this->customer();
        $cashier = User::factory()->create();
        $cashier->roles()->attach(Role::where('name', 'cashier')->firstOrFail());
        $this->actingAs($cashier);

        $this->deleteJson("/api/v1/customers/{$id}")->assertForbidden();
        $this->assertNotNull(Customer::find($id));
    }
}
