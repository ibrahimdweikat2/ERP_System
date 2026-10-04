<?php

namespace Tests\Feature\Platform;

use App\Domains\Identity\Models\Role;
use App\Domains\Identity\RoleTemplates;
use App\Domains\Platform\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** name => [label, sorted permission names] for the current company. */
    private function roleMatrix(): array
    {
        return Role::with('permissions')->orderBy('name')->get()
            ->mapWithKeys(fn (Role $r) => [$r->name => [$r->label, $r->permissions->pluck('name')->sort()->values()->all()]])->all();
    }

    public function test_superadmin_signs_in_on_the_shared_page_and_sees_only_the_platform(): void
    {
        $this->platformAdmin();
        $this->postJson('/api/v1/auth/login', ['email' => 'platform@example.test', 'password' => 'SecurePass123!'])->assertOk()
            ->assertJsonPath('data.is_platform_admin', true)->assertJsonPath('data.company', null)->assertJsonPath('data.permissions', []);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.is_platform_admin', true);
        $this->getJson('/api/v1/platform/companies')->assertOk();
        // Company pages are not the platform's: no company, no company data.
        $this->getJson('/api/v1/products')->assertForbidden();
        $this->getJson('/api/v1/users')->assertForbidden();
        $this->getJson('/api/v1/store/context')->assertForbidden();
    }

    public function test_company_users_cannot_reach_the_platform(): void
    {
        $this->actingAs($this->companyOwner(1));
        $this->getJson('/api/v1/platform/companies')->assertForbidden();
        $this->postJson('/api/v1/platform/companies', ['name' => 'X'])->assertForbidden();
        $this->getJson('/api/v1/platform/operations/health')->assertForbidden();
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.is_platform_admin', false)->assertJsonPath('data.company.id', 1);
    }

    public function test_new_company_gets_the_same_roles_and_a_complete_baseline(): void
    {
        $this->actingAs($this->platformAdmin());
        $id = $this->postJson('/api/v1/platform/companies', ['name' => 'شركة النور'])->assertCreated()
            ->assertJsonPath('data.name', 'شركة النور')->assertJsonPath('data.status', 'active')->json('data.id');
        $this->postJson('/api/v1/platform/companies', ['name' => 'شركة النور'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/platform/companies', ['name' => ''])->assertUnprocessable()->assertJsonValidationErrors('name');

        $expected = $this->roleMatrix();
        $this->assertSame(array_keys(RoleTemplates::ROLES), array_values(array_intersect(array_keys(RoleTemplates::ROLES), array_keys($expected))));
        $this->companyContext()->run($id, function () use ($expected) {
            $this->assertSame($expected, $this->roleMatrix());
            $this->assertSame(28, DB::table('accounts')->count());
            $this->assertSame(9, DB::table('journals')->count());
            $this->assertSame(16, DB::table('document_sequences')->count());
            $this->assertSame(6, DB::table('stock_locations')->count());
            $this->assertSame(24, DB::table('account_mappings')->count());
            $this->assertSame(3, DB::table('currencies')->count());
            $this->assertSame(1, DB::table('purchasing_invoice_policies')->count());
            $this->assertSame('شركة النور', DB::table('store_settings')->value('trade_name'));
        });
        $this->getJson("/api/v1/platform/companies/$id")->assertOk()->assertJsonCount(5, 'data.roles');
        $this->getJson('/api/v1/platform/audit-logs')->assertOk()->assertJsonFragment(['action' => 'platform.company_created']);
    }

    public function test_superadmin_creates_an_owner_who_signs_in_to_their_company_only(): void
    {
        $this->actingAs($this->platformAdmin());
        $id = $this->postJson('/api/v1/platform/companies', ['name' => 'شركة الأمل'])->json('data.id');
        $owner = ['name' => 'مالك الأمل', 'email' => 'owner@amal.test', 'phone' => '0599111222', 'password' => 'AmalOwner2026!'];
        $this->postJson("/api/v1/platform/companies/$id/owners", [...$owner, 'password' => 'weak'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson("/api/v1/platform/companies/$id/owners", $owner)->assertCreated()->assertJsonPath('data.email', 'owner@amal.test');
        $this->postJson("/api/v1/platform/companies/$id/owners", $owner)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->getJson("/api/v1/platform/companies/$id/owners")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'owner@amal.test');
        $listed = collect($this->getJson('/api/v1/platform/companies')->json('data'))->firstWhere('id', $id);
        $this->assertSame(1, $listed['owners_count']);

        // Tests share one app across requests: drop cached guards so the next request signs in afresh.
        auth()->guard('web')->logout();
        auth()->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'owner@amal.test', 'password' => 'AmalOwner2026!'])->assertOk()
            ->assertJsonPath('data.company.id', $id)->assertJsonPath('data.is_owner', true);
        $this->getJson('/api/v1/store/context')->assertOk()->assertJsonPath('data.trade_name', 'شركة الأمل');
        $this->getJson('/api/v1/users')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/roles')->assertOk()->assertJsonCount(5, 'data');
    }

    public function test_suspending_a_company_blocks_its_users(): void
    {
        $company = $this->makeCompany('شركة موقوفة');
        $this->companyOwner($company, 'stop@example.test');
        $this->actingAs($this->platformAdmin());
        $this->patchJson("/api/v1/platform/companies/{$company->id}", ['status' => 'suspended'])->assertOk()->assertJsonPath('data.status', 'suspended');
        // Tests share one app across requests: drop cached guards so the next request signs in afresh.
        auth()->guard('web')->logout();
        auth()->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'stop@example.test', 'password' => 'SecurePass123!'])->assertForbidden()->assertJsonPath('code', 'COMPANY_SUSPENDED');
        $this->assertSame(Company::SUSPENDED, $company->fresh()->status);
    }

    public function test_superadmin_is_created_from_the_console_with_two_factor_required(): void
    {
        $this->artisan('erp:create-superadmin', ['email' => 'root@example.test'])
            ->expectsQuestion('Superadmin password (12+ characters, mixed case and numbers)', 'RootPassword2026!')->assertSuccessful();
        $admin = $this->companyContext()->platform(fn () => User::where('email', 'root@example.test')->firstOrFail());
        $this->assertTrue($admin->is_platform_admin);
        $this->assertNull($admin->company_id);
        $this->assertTrue($admin->mfa_required);
        $this->postJson('/api/v1/auth/login', ['email' => 'root@example.test', 'password' => 'RootPassword2026!'])->assertOk()->assertJsonPath('data.mfa', 'setup');
    }
}
