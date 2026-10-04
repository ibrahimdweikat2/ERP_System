<?php

use App\Support\Tenancy\TenancySchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Multi-company step 3: currencies, approval/business policies and account mappings
// are keyed by a code. Each company gets its own rows, so the primary key becomes
// (company_id, code) and every foreign key to them becomes composite.
return new class extends Migration
{
    public function up(): void
    {
        foreach (TenancySchema::KEYED as $parent => $key) {
            $references = array_values(array_filter(TenancySchema::referencing($parent), fn ($fk) => $fk['referenced'] === [$key]));
            foreach ($references as $fk) {
                DB::statement("ALTER TABLE `{$fk['table']}` DROP FOREIGN KEY `{$fk['name']}`");
            }
            if ((TenancySchema::uniqueIndexes($parent)['PRIMARY'] ?? []) !== ['company_id', $key]) {
                DB::statement("ALTER TABLE `$parent` DROP PRIMARY KEY, ADD PRIMARY KEY (`company_id`, `$key`)");
            }
            foreach ($references as $fk) {
                $column = $fk['columns'][0];
                $name = TenancySchema::name($fk['table'].'_'.$column.'_company_foreign');
                if (! TenancySchema::foreignKeyExists($fk['table'], $name)) {
                    DB::statement("ALTER TABLE `{$fk['table']}` ADD CONSTRAINT `$name` FOREIGN KEY (`company_id`, `$column`) REFERENCES `$parent` (`company_id`, `$key`) ON DELETE {$fk['delete']} ON UPDATE {$fk['update']}");
                }
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Multi-company migrations are not reversible; restore the pre-migration backup.');
    }
};
