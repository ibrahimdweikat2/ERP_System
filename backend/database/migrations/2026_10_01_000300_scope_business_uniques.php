<?php

use App\Support\Tenancy\TenancySchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Multi-company step 4: codes and document numbers are unique within a company,
// so two companies can each have SKU "A1" or invoice "SI/2026/000001".
return new class extends Migration
{
    public function up(): void
    {
        foreach (TenancySchema::COMPANY_UNIQUES as $table => $columns) {
            $indexes = TenancySchema::uniqueIndexes($table);
            $scoped = ['company_id', ...$columns];
            if (! in_array($scoped, $indexes, true)) {
                $name = TenancySchema::name($table.'_company_'.implode('_', $columns).'_unique');
                $list = implode(', ', array_map(fn ($c) => "`$c`", $scoped));
                DB::statement("ALTER TABLE `$table` ADD UNIQUE `$name` ($list)");
            }
            foreach ($indexes as $index => $indexColumns) {
                if ($index !== 'PRIMARY' && $indexColumns === $columns) {
                    DB::statement("ALTER TABLE `$table` DROP INDEX `$index`");
                }
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Multi-company migrations are not reversible; restore the pre-migration backup.');
    }
};
