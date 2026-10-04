<?php

namespace Database\Seeders;

use App\Domains\Identity\Models\Permission;
use App\Domains\Identity\PermissionCatalog;
use Illuminate\Database\Seeder;

/** Platform-wide: the permission catalog is shared by every company. */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionCatalog::names() as $name) {
            Permission::firstOrCreate(['name' => $name]);
        }
    }
}
