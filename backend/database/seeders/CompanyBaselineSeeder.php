<?php

namespace Database\Seeders;

use App\Domains\Identity\Models\Permission;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\RoleTemplates;
use App\Domains\Platform\Models\Company;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * What every company starts with: policies, document sequences, chart of accounts,
 * journals, stock locations, store settings and the standard roles. Runs inside the
 * company's context and is idempotent: existing (possibly customized) rows are kept.
 */
class CompanyBaselineSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::findOrFail(app(CompanyContext::class)->requireCompanyId());
        foreach (['inventory_adjustment', 'purchase_order'] as $key) {
            DB::table('approval_policies')->insertOrIgnore(['key' => $key, 'threshold' => '0', 'segregate_requester' => false, 'created_at' => now(), 'updated_at' => now()]);
        }
        // Defaults only: an existing configured policy is never overwritten.
        foreach ([
            'sales' => ['discount_percent' => '0', 'return_window_days' => 30, 'segregate_requester' => false],
            'installments' => ['minimum_down_payment_percent' => '0', 'markup_recognition' => 'unconfigured', 'segregate_requester' => false, 'allow_credit_override' => true, 'grace_days' => 0],
            'treasury' => ['enforce_sessions' => false, 'expense_approval_threshold' => '0', 'variance_approval_threshold' => '0', 'segregate_requester' => false],
            'checks' => ['ar_recognition' => 'on_receipt', 'allow_early_deposit' => false, 'segregate_requester' => false],
        ] as $key => $settings) {
            DB::table('business_policies')->insertOrIgnore(['key' => $key, 'settings' => json_encode($settings, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->call(SequenceSeeder::class);
        DB::table('purchasing_invoice_policies')->insertOrIgnore(['created_at' => now(), 'updated_at' => now()]);
        $this->call(AccountingTemplateSeeder::class);
        $this->call(CatalogMasterSeeder::class);
        $store = StoreSetting::current();
        if ($store->trade_name === '') {
            $store->update(['trade_name' => $company->name]);
        }
        foreach (array_keys(RoleTemplates::ROLES) as $name) {
            $role = Role::firstOrCreate(['name' => $name], ['label' => RoleTemplates::ROLES[$name][0]]);
            // Re-seeding preserves the owner's later role customizations.
            if ($role->wasRecentlyCreated || $name === RoleTemplates::OWNER) {
                $role->permissions()->sync(Permission::whereIn('name', RoleTemplates::permissionsFor($name))->pluck('id'));
            }
        }
    }
}
