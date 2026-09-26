<?php

namespace Database\Seeders;

use App\Domains\Identity\Models\Permission;
use App\Domains\Identity\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
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
        DB::table('purchasing_invoice_policies')->insertOrIgnore(['id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->call(AccountingTemplateSeeder::class);
        $this->call(CatalogMasterSeeder::class);
        $groups = [
            'sales' => 'view,create,post,cancel,discount,override_price,view_cost,view_profit',
            'installments' => 'view,create,approve,reschedule,early_settlement',
            'checks' => 'view,receive,deposit,clear,bounce,replace,return_to_customer',
            'inventory' => 'view,receive,transfer,adjust,count,view_cost',
            'purchasing' => 'view,create,approve,receive,receive_without_po,invoice,pay,delete_supplier',
            'accounting' => 'view,journal_create,post,reverse,period_lock',
            'reports' => 'view,financial,vat', 'catalog' => 'view,manage',
            'customers' => 'view,manage,delete', 'payments' => 'view,receive',
            'cashbank' => 'view,manage', 'expenses' => 'view,create,approve',
            'approvals' => 'view,decide', 'audit' => 'view',
            'users' => 'manage', 'roles' => 'manage', 'settings' => 'manage',
        ];
        foreach ($groups as $domain => $actions) {
            foreach (explode(',', $actions) as $action) {
                Permission::firstOrCreate(['name' => "$domain.$action"]);
            }
        }
        $roles = [
            'owner' => ['المالك', Permission::pluck('name')->all()],
            'accountant' => ['المحاسب', ['accounting.view', 'accounting.journal_create', 'accounting.post', 'accounting.reverse', 'reports.view', 'reports.financial', 'reports.vat', 'customers.view', 'purchasing.view', 'purchasing.invoice', 'purchasing.pay', 'checks.view', 'checks.deposit', 'checks.clear', 'checks.bounce', 'payments.view', 'payments.receive', 'cashbank.view', 'expenses.view', 'expenses.create', 'audit.view']],
            'cashier' => ['المبيعات والصندوق', ['sales.view', 'sales.create', 'sales.post', 'catalog.view', 'inventory.view', 'customers.view', 'customers.manage', 'payments.view', 'payments.receive']],
            'inventory' => ['المخزون والمشتريات', ['catalog.view', 'catalog.manage', 'inventory.view', 'inventory.receive', 'inventory.transfer', 'inventory.count', 'purchasing.view', 'purchasing.create', 'purchasing.receive']],
            'collections' => ['التحصيل', ['customers.view', 'installments.view', 'payments.view', 'payments.receive', 'checks.view', 'checks.receive']],
        ];
        foreach ($roles as $name => [$label, $permissions]) {
            $role = Role::firstOrCreate(['name' => $name], ['label' => $label]);
            // Re-seeding preserves the owner's later role customizations.
            if ($role->wasRecentlyCreated || $name === 'owner') {
                $role->permissions()->sync(Permission::whereIn('name', $permissions)->pluck('id'));
            }
        }
    }
}
