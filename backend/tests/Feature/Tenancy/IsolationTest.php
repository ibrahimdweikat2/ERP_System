<?php

namespace Tests\Feature\Tenancy;

use App\Domains\Catalog\Models\Unit;
use App\Domains\Identity\Models\Role;
use App\Domains\StoreSetup\Actions\NextDocumentNumber;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Two companies on one database: neither can see, change or reference the other's data. */
class IsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function product(string $sku, string $barcode): array
    {
        return ['sku' => $sku, 'name_ar' => 'منتج '.$sku, 'unit_id' => Unit::firstOrFail()->id, 'serial_tracked' => false, 'active' => true,
            'standard_cost' => '100', 'cash_price' => '150', 'installment_price' => '180', 'minimum_price' => '120', 'reorder_level' => '0', 'barcodes' => [$barcode]];
    }

    public function test_company_data_is_invisible_and_unreachable_from_another_company(): void
    {
        $ownerA = $this->companyOwner(1, 'a@example.test');
        $second = $this->makeCompany();
        $ownerB = $this->companyOwner($second, 'b@example.test');

        $this->actingAs($ownerA);
        $productA = $this->postJson('/api/v1/products', $this->product('ISO-1', 'BAR-1'))->assertCreated()->json('data.id');

        $this->actingAs($ownerB);
        // The same SKU and barcode are free in another company.
        $unitB = $this->companyContext()->run($second->id, fn () => Unit::firstOrFail()->id);
        $productB = $this->postJson('/api/v1/products', [...$this->product('ISO-1', 'BAR-1'), 'unit_id' => $unitB])->assertCreated()->json('data.id');
        $this->assertNotSame($productA, $productB);
        $this->assertSame([$productB], collect($this->getJson('/api/v1/products?per_page=100')->assertOk()->json('data'))->pluck('id')->all());
        // Route-model binding resolves inside the company: another company's id does not exist.
        $this->getJson("/api/v1/products/$productA")->assertNotFound();
        $this->putJson("/api/v1/products/$productA", [...$this->product('HACK', 'HACK-1'), 'unit_id' => $unitB])->assertNotFound();
        $this->deleteJson("/api/v1/products/$productA")->assertNotFound();
        // A foreign id inside a payload fails validation: "exists" only sees the company's rows.
        $unitA = Unit::firstOrFail()->id;
        $this->postJson('/api/v1/products', [...$this->product('ISO-2', 'BAR-2'), 'unit_id' => $unitA])->assertUnprocessable()->assertJsonValidationErrors('unit_id');

        // Users, roles and audit trail are per company.
        $this->assertSame(['b@example.test'], collect($this->getJson('/api/v1/users')->assertOk()->json('data'))->pluck('email')->all());
        $roleA = Role::where('name', 'cashier')->value('id');
        $this->postJson('/api/v1/users', ['name' => 'X', 'email' => 'x@example.test', 'status' => 'active', 'password' => 'SecurePass123!', 'role_ids' => [$roleA]])
            ->assertUnprocessable()->assertJsonValidationErrors('role_ids.0');
        // An email signs in to one account on the whole platform.
        $roleB = $this->companyContext()->run($second->id, fn () => Role::where('name', 'cashier')->value('id'));
        $this->postJson('/api/v1/users', ['name' => 'X', 'email' => 'a@example.test', 'status' => 'active', 'password' => 'SecurePass123!', 'role_ids' => [$roleB]])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertNotContains($productA, collect($this->getJson('/api/v1/audit-logs?per_page=100')->assertOk()->json('data'))->where('entity_type', 'product')->pluck('entity_id')->map(fn ($id) => (int) $id)->all());
        $this->getJson('/api/v1/store/context')->assertOk()->assertJsonPath('data.trade_name', $second->name);

        // The original company still sees only its own product.
        $this->actingAs($ownerA);
        $this->assertSame([$productA], collect($this->getJson('/api/v1/products?per_page=100')->json('data'))->pluck('id')->all());
    }

    public function test_each_company_numbers_its_documents_from_one(): void
    {
        $second = $this->makeCompany();
        $next = fn () => app(NextDocumentNumber::class)->execute('purchase_order', '2026-09-01');
        $this->assertSame('PO/2026/000001', $next());
        $this->assertSame('PO/2026/000002', $next());
        $this->assertSame('PO/2026/000001', $this->companyContext()->run($second->id, $next));
    }

    public function test_queued_jobs_run_as_the_company_that_dispatched_them(): void
    {
        $second = $this->makeCompany();
        $this->companyContext()->run($second->id, function () {
            // Dispatched (pushed) inside the company's context; static so the test case is not serialized.
            dispatch(static function () {
                cache()->put('job-company', app(CompanyContext::class)->companyId());
                cache()->put('job-roles', DB::table('roles')->count());
            });
        });
        Artisan::call('queue:work', ['--once' => true, '--stop-when-empty' => true]);
        $this->assertSame($second->id, cache()->get('job-company'));
        $this->assertSame(5, cache()->get('job-roles'));
    }

    public function test_suspended_company_cannot_sign_in(): void
    {
        $second = $this->makeCompany();
        $owner = $this->companyOwner($second, 'suspended@example.test');
        $second->update(['status' => 'suspended']);
        $this->postJson('/api/v1/auth/login', ['email' => 'suspended@example.test', 'password' => 'SecurePass123!'])->assertForbidden()->assertJsonPath('code', 'COMPANY_SUSPENDED');
        $this->actingAs($owner)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }
}
