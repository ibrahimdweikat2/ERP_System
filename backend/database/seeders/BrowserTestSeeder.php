<?php

namespace Database\Seeders;

use App\Domains\Identity\Models\Role;
use App\Domains\Platform\Actions\ProvisionCompanyBaseline;
use App\Domains\Platform\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Seeder;

class BrowserTestSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('e2e') || ! str_ends_with(config('database.connections.mysql.database'), '_test')) {
            throw new \RuntimeException('Dedicated browser test environment required');
        }
        $this->call(DatabaseSeeder::class);
        $context = app(CompanyContext::class);
        $password = 'BrowserTest!'.bin2hex(random_bytes(10)).'A1';
        [$user, $scenarios] = $context->run(1, function () use ($password) {
            $user = User::updateOrCreate(['email' => 'owner@example.test'], ['name' => 'مالك المتجر', 'password' => $password, 'status' => 'active']);
            $user->roles()->sync([Role::where('name', 'owner')->value('id')]);
            $scenarios = [];
            foreach (['accounting', 'catalog', 'inventory', 'purchase-orders', 'goods-receipts', 'suppliers', 'supplier-invoices'] as $scenario) {
                $account = User::updateOrCreate(['email' => $scenario.'@example.test'], ['name' => 'اختبار '.$scenario, 'password' => $password, 'status' => 'active']);
                $account->roles()->sync([Role::where('name', 'owner')->value('id')]);
                $scenarios[$scenario] = ['email' => $account->email, 'password' => $password];
            }

            return [$user, $scenarios];
        });
        // A second company proves the browser never shows another company's records.
        $second = Company::firstOrCreate(['name' => 'شركة اختبار ثانية'], ['status' => Company::ACTIVE]);
        app(ProvisionCompanyBaseline::class)->execute($second);
        $context->run($second->id, function () use ($password) {
            $owner = User::updateOrCreate(['email' => 'second-owner@example.test'], ['name' => 'مالك الشركة الثانية', 'password' => $password, 'status' => 'active']);
            $owner->roles()->sync([Role::where('name', 'owner')->value('id')]);
        });
        // The superadmin signs in without two-factor in this dedicated test environment only.
        $context->platform(function () use ($password) {
            $admin = User::where('email', 'platform@example.test')->first() ?? new User;
            $admin->forceFill(['name' => 'مدير المنصة', 'email' => 'platform@example.test', 'password' => $password, 'status' => 'active', 'is_platform_admin' => true, 'company_id' => null, 'mfa_required' => false])->save();
        });
        file_put_contents(base_path('../.runtime/e2e-credentials.json'), json_encode(['email' => $user->email, 'password' => $password, 'scenarios' => $scenarios,
            'platform' => ['email' => 'platform@example.test', 'password' => $password], 'second_company' => ['email' => 'second-owner@example.test', 'password' => $password]]));
    }
}
