<?php

namespace Database\Seeders;

use App\Domains\Identity\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class BrowserTestSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('e2e') || ! str_ends_with(config('database.connections.mysql.database'), '_test')) {
            throw new \RuntimeException('Dedicated browser test environment required');
        }
        $this->call(DatabaseSeeder::class);
        $password = 'BrowserTest!'.bin2hex(random_bytes(10)).'A1';
        $user = User::updateOrCreate(['email' => 'owner@example.test'], ['name' => 'مالك المتجر', 'password' => $password, 'status' => 'active']);
        $user->roles()->sync([Role::where('name', 'owner')->value('id')]);
        $scenarios = [];
        foreach (['accounting', 'catalog', 'inventory', 'purchase-orders', 'goods-receipts', 'suppliers', 'supplier-invoices'] as $scenario) {
            $account = User::updateOrCreate(['email' => $scenario.'@example.test'], ['name' => 'اختبار '.$scenario, 'password' => $password, 'status' => 'active']);
            $account->roles()->sync([Role::where('name', 'owner')->value('id')]);
            $scenarios[$scenario] = ['email' => $account->email, 'password' => $password];
        }
        file_put_contents(base_path('../.runtime/e2e-credentials.json'), json_encode(['email' => $user->email, 'password' => $password, 'scenarios' => $scenarios]));
    }
}
