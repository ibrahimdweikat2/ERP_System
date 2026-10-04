<?php

use App\Support\Tenancy\TenancySchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Multi-company step 5: store settings and the purchasing invoice policy were single
// rows (id = 1). They become one row per company.
return new class extends Migration
{
    public function up(): void
    {
        if (TenancySchema::checkExists('store_settings', 'single_store')) {
            DB::statement('ALTER TABLE `store_settings` DROP CHECK `single_store`');
        }
        if ((TenancySchema::column('store_settings', 'id')['column_type'] ?? '') !== 'bigint unsigned') {
            DB::statement('ALTER TABLE `store_settings` MODIFY `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
        }
        foreach (TenancySchema::SINGLETONS as $table) {
            if (! in_array(['company_id'], TenancySchema::uniqueIndexes($table), true)) {
                DB::statement("ALTER TABLE `$table` ADD UNIQUE `{$table}_company_id_unique` (`company_id`)");
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Multi-company migrations are not reversible; restore the pre-migration backup.');
    }
};
