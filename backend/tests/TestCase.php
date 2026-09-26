<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

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

    /**
     * A role holding every permission without being the owner. The owner's own
     * requests are approved automatically, so approval-workflow tests act as this.
     */
    protected function managerRole(): \App\Domains\Identity\Models\Role
    {
        $role = \App\Domains\Identity\Models\Role::firstOrCreate(['name' => 'test_manager'], ['label' => 'مدير اختبار']);
        $role->permissions()->sync(\App\Domains\Identity\Models\Permission::pluck('id'));

        return $role;
    }
}
