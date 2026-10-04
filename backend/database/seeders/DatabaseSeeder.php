<?php

namespace Database\Seeders;

use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Shared permissions, then the baseline of company 1 (the original store). */
    public function run(): void
    {
        $this->call(PermissionSeeder::class);
        app(CompanyContext::class)->run(1, fn () => $this->call(CompanyBaselineSeeder::class));
    }
}
