<?php

namespace Tests;

use App\Domains\Identity\Models\Role;
use App\Domains\Platform\Actions\ProvisionCompanyBaseline;
use App\Domains\Platform\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Hash;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        // Laravel runs migration traits inside parent::setUp(). Validate the
        // target while creating the app, before any of those traits can execute.
        if ($app['config']->get('database.default') !== 'mysql' || ! str_ends_with((string) $app['config']->get('database.connections.mysql.database'), '_test')) {
            throw new \RuntimeException('Tests require a dedicated database whose name ends in _test.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $context = $this->app->make(CompanyContext::class);
        $context->clear();
        // DatabaseTruncation empties companies too; company 1 is the original store every test starts in.
        if (Company::whereKey(1)->doesntExist()) {
            Company::forceCreate(['id' => 1, 'name' => config('erp.default_company_name'), 'status' => Company::ACTIVE]);
        }
        // Tests act inside the original store (company 1) unless they switch explicitly.
        $context->setCompany(1);
    }

    // RefreshDatabase / DatabaseTruncation rebuild or empty every table, across all companies.
    protected function setUpTraits()
    {
        $context = $this->app->make(CompanyContext::class);
        $context->enterBypass();
        try {
            return parent::setUpTraits();
        } finally {
            $context->clear();
        }
    }

    protected function companyContext(): CompanyContext
    {
        return $this->app->make(CompanyContext::class);
    }

    /**
     * A role holding every permission without being the owner. The owner's own
     * requests are approved automatically, so approval-workflow tests act as this.
     */
    protected function managerRole(): Role
    {
        $role = Role::firstOrCreate(['name' => 'test_manager'], ['label' => 'مدير اختبار']);
        $role->permissions()->sync(\App\Domains\Identity\Models\Permission::pluck('id'));

        return $role;
    }

    /** A further company with the standard baseline (roles, accounts, sequences...). */
    protected function makeCompany(string $name = 'شركة ثانية'): Company
    {
        $company = Company::create(['name' => $name, 'status' => Company::ACTIVE]);
        $this->app->make(ProvisionCompanyBaseline::class)->execute($company);

        return $company;
    }

    /** An owner account inside the given company. */
    protected function companyOwner(Company|int $company, ?string $email = null): User
    {
        $id = $company instanceof Company ? $company->id : $company;

        return $this->companyContext()->run($id, function () use ($email) {
            $user = User::factory()->create(['email' => $email ?? 'owner'.uniqid().'@example.test', 'password' => Hash::make('SecurePass123!')]);
            $user->roles()->attach(Role::where('name', 'owner')->firstOrFail());

            return $user;
        });
    }

    protected function platformAdmin(string $email = 'platform@example.test'): User
    {
        return $this->companyContext()->platform(function () use ($email) {
            $user = new User;
            $user->forceFill(['name' => 'مدير المنصة', 'email' => $email, 'password' => Hash::make('SecurePass123!'), 'status' => 'active', 'is_platform_admin' => true, 'company_id' => null])->save();

            return $user;
        });
    }
}
