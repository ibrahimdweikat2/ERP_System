<?php

use App\Support\Tenancy\TenancySchema;
use App\Support\Tenancy\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Multi-company step 6: drop the temporary "DEFAULT 1". From now on a row written
// without a company fails instead of silently landing in company 1.
return new class extends Migration
{
    public function up(): void
    {
        foreach (TenancySchema::companyTables() as $table) {
            if (TenantTables::kind($table) === TenantTables::COMPANY && (TenancySchema::column($table, 'company_id')['column_default'] ?? null) !== null) {
                DB::statement("ALTER TABLE `$table` ALTER COLUMN `company_id` DROP DEFAULT");
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Multi-company migrations are not reversible; restore the pre-migration backup.');
    }
};
