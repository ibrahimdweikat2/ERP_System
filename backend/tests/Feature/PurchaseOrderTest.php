<?php

namespace Tests\Feature;

use App\Domains\Accounting\Models\Account;
use App\Domains\Catalog\Actions\SaveProduct;
use App\Domains\Catalog\Models\Unit;
use App\Domains\Identity\Models\Permission;
use App\Domains\Identity\Models\Role;
use App\Domains\Purchasing\Actions\SaveSupplier;
use App\Domains\Purchasing\Models\PurchaseOrder;
use App\Domains\StoreSetup\Models\ExchangeRate;
use App\Domains\Tax\Models\TaxCode;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $supplier;

    private int $product;

    private int $tax;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->owner = $this->loginRole('owner');
        $this->supplier = app(SaveSupplier::class)->execute(['code' => 'PO-SUP', 'legal_name' => 'مورد أمر شراء', 'currency' => 'ILS', 'contacts' => [], 'payment_terms_days' => 30, 'credit_limit' => '10000', 'active' => true], $this->owner->id)->id;
        $this->product = app(SaveProduct::class)->execute(['sku' => 'PO-SKU', 'name_ar' => 'جهاز أمر شراء', 'unit_id' => Unit::firstOrFail()->id, 'serial_tracked' => true, 'active' => true, 'standard_cost' => '100', 'cash_price' => '200', 'installment_price' => '250', 'minimum_price' => '150', 'reorder_level' => '1', 'barcodes' => []], $this->owner->id)->id;
        $this->tax = TaxCode::create(['code' => 'TEST10', 'name_ar' => 'ضريبة اختبار فقط', 'category' => 'standard', 'rate' => '10', 'effective_from' => '2026-01-01', 'input_account_id' => Account::where('code', '1400')->value('id'), 'output_account_id' => Account::where('code', '2200')->value('id')])->id;
    }

    private function loginRole(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach($role === 'owner' ? $this->managerRole() : Role::where('name', $role)->firstOrFail());
        $this->actingAs($user);

        return $user;
    }

    private function data(array $extra = []): array
    {
        return ['supplier_id' => $this->supplier, 'document_date' => '2026-09-01', 'currency' => 'ILS', 'lines' => [['product_id' => $this->product, 'quantity' => '3', 'unit_price' => '110.0001', 'discount_amount' => '0.0001', 'tax_code_id' => $this->tax, 'tax_inclusive' => true]], ...$extra];
    }

    private function createOrder(array $extra = []): int
    {
        return $this->postJson('/api/v1/purchase-orders', $this->data($extra), ['Idempotency-Key' => 'po-create-'.uniqid()])->assertCreated()->json('data.id');
    }

    private function event(int $id, string $event, int $version = 1, array $data = []): TestResponse
    {
        return $this->postJson('/api/v1/purchase-orders/'.$id.'/'.$event, ['version' => $version, ...$data], ['Idempotency-Key' => 'po-event-'.uniqid()]);
    }

    private function approved(int $id): void
    {
        $this->event($id, 'submit')->assertOk()->assertJsonPath('data.status', 'pending');
        $this->event($id, 'decide', 1, ['decision' => 'approved', 'reason' => 'مراجعة السعر والمورد والكميات'])->assertOk()->assertJsonPath('data.status', 'approved');
    }

    public function test_order_rounding_frozen_snapshots_and_repeated_issue_without_stock_or_gl(): void
    {
        $id = $this->createOrder();
        $this->getJson('/api/v1/purchase-orders/'.$id)->assertOk()->assertJsonPath('data.subtotal', '300.0002')->assertJsonPath('data.tax_total', '30.0000')->assertJsonPath('data.total', '330.0002');
        $this->event($id, 'issue')->assertUnprocessable()->assertJsonPath('code', 'PURCHASE_ORDER_NOT_APPROVED');
        $this->approved($id);
        DB::table('suppliers')->where('id', $this->supplier)->update(['legal_name' => 'اسم جديد']);
        $this->event($id, 'issue')->assertOk()->assertJsonPath('data.document_no', 'PO/2026/000001')->assertJsonPath('data.supplier_snapshot.legal_name', 'مورد أمر شراء');
        $this->event($id, 'issue')->assertOk()->assertJsonPath('data.document_no', 'PO/2026/000001');
        $this->putJson('/api/v1/purchase-orders/'.$id, $this->data(['version' => 1]))->assertConflict();
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'purchasing.order_issued')->count());
        foreach (['purchase_orders', 'purchase_order_lines'] as $table) {
            try {
                DB::table($table)->where($table === 'purchase_orders' ? 'id' : 'purchase_order_id', $id)->delete();
                $this->fail('Issued order deleted');
            } catch (QueryException $e) {
                $this->assertStringContainsString('immutable', $e->getMessage());
            }
        }
    }

    public function test_draft_edit_supersedes_approval_and_stale_decision_cannot_issue_new_values(): void
    {
        $id = $this->createOrder();
        $this->approved($id);
        $approval = PurchaseOrder::findOrFail($id)->approval_id;
        $this->putJson('/api/v1/purchase-orders/'.$id, $this->data(['version' => 1, 'notes' => 'تغيير يستلزم موافقة جديدة']))->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.status', 'draft');
        $this->assertDatabaseHas('approvals', ['id' => $approval, 'status' => 'superseded']);
        $this->event($id, 'issue', 2)->assertUnprocessable();
        $this->event($id, 'decide', 1, ['decision' => 'approved', 'reason' => 'قرار متأخر للنسخة القديمة'])->assertConflict();
        $this->assertDatabaseCount('document_sequence_counters', 0);
    }

    public function test_policy_segregation_permissions_and_automatic_approval_are_revalidated(): void
    {
        $id = $this->createOrder();
        $this->putJson('/api/v1/purchasing/order-policy', ['threshold' => '0', 'segregate_requester' => true, 'reason' => 'فصل منشئ الطلب عن المعتمد'])->assertOk();
        $this->event($id, 'submit')->assertOk();
        $this->event($id, 'decide', 1, ['decision' => 'approved', 'reason' => 'محاولة اعتماد ذاتية'])->assertForbidden()->assertJsonPath('code', 'SELF_APPROVAL_FORBIDDEN');
        $this->loginRole('inventory');
        $this->event($id, 'decide', 1, ['decision' => 'approved', 'reason' => 'محاولة موظف غير مخول'])->assertForbidden();
        $this->loginRole('owner');
        $this->event($id, 'decide', 1, ['decision' => 'approved', 'reason' => 'اعتماد المراجع المستقل'])->assertOk();
        $this->putJson('/api/v1/purchasing/order-policy', ['threshold' => '1000', 'segregate_requester' => false, 'reason' => 'تغيير قيمة حد الموافقة'])->assertOk();
        $this->event($id, 'issue')->assertConflict();
        $automatic = $this->createOrder();
        $this->event($automatic, 'submit')->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.approval_id', null);
        $this->putJson('/api/v1/purchasing/order-policy', ['threshold' => '0', 'segregate_requester' => false, 'reason' => 'استعادة مراجعة جميع الأوامر'])->assertOk();
        $this->event($automatic, 'issue')->assertConflict();
    }

    public function test_rate_tax_and_amount_validation_preserve_historical_provenance(): void
    {
        $this->postJson('/api/v1/purchase-orders', $this->data(['currency' => 'USD']), ['Idempotency-Key' => 'po-no-exchange-rate'])->assertUnprocessable()->assertJsonPath('code', 'EXCHANGE_RATE_MISSING');
        ExchangeRate::create(['currency_code' => 'USD', 'rate_date' => '2026-08-01', 'rate_to_base' => '3.50000001', 'source' => str_repeat('x', 150), 'created_by' => $this->owner->id, 'created_at' => now()]);
        $id = $this->createOrder(['currency' => 'USD']);
        $this->getJson('/api/v1/purchase-orders/'.$id)->assertOk()->assertJsonPath('data.base_total', '1155.0007')->assertJsonPath('data.exchange_rate', '3.50000001');
        ExchangeRate::create(['currency_code' => 'USD', 'rate_date' => '2026-09-01', 'rate_to_base' => '4', 'source' => 'later rate', 'created_by' => $this->owner->id, 'created_at' => now()]);
        $this->approved($id);
        $this->event($id, 'issue')->assertOk()->assertJsonPath('data.exchange_rate', '3.50000001');
        $bad = $this->data();
        $bad['lines'][0]['discount_amount'] = '9999';
        $this->postJson('/api/v1/purchase-orders', $bad, ['Idempotency-Key' => 'po-bad-discount'])->assertUnprocessable()->assertJsonPath('code', 'DISCOUNT_EXCEEDS_LINE');
        $bad['lines'][0]['discount_amount'] = '0';
        $bad['lines'][0]['quantity'] = '1.5';
        $this->postJson('/api/v1/purchase-orders', $bad, ['Idempotency-Key' => 'po-fractional-device'])->assertUnprocessable()->assertJsonPath('code', 'QUANTITY_PRECISION');
        $this->postJson('/api/v1/purchase-orders', $this->data(['document_date' => '2025-12-01']), ['Idempotency-Key' => 'po-old-tax'])->assertUnprocessable()->assertJsonPath('code', 'TAX_CODE_NOT_EFFECTIVE');
    }

    public function test_inactive_supplier_blocks_issue_and_approval_list_does_not_leak_purchasing(): void
    {
        $id = $this->createOrder();
        $this->approved($id);
        DB::table('suppliers')->where('id', $this->supplier)->update(['active' => false]);
        $this->event($id, 'issue')->assertUnprocessable()->assertJsonPath('code', 'SUPPLIER_INACTIVE');
        $role = Role::create(['name' => 'inventory_approver', 'label' => 'مراجع مخزون فقط']);
        $role->permissions()->attach(Permission::whereIn('name',['approvals.view', 'inventory.view_cost'])->pluck('id'));
        $this->loginRole('inventory_approver');
        $this->getJson('/api/v1/approvals')->assertOk()->assertJsonPath('total',0);
        $this->getJson('/api/v1/purchase-orders/'.$id)->assertForbidden();
    }
}
