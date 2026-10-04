<?php

use App\Support\Tenancy\TenancySchema;
use App\Support\Tenancy\TenantTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Multi-company step 2: company_id on every company table.
// "ADD COLUMN ... DEFAULT 1" assigns existing rows to company 1 without an UPDATE,
// so the append-only and immutability triggers never fire. The default is dropped
// in the final step, after which a missing company_id fails loudly.
return new class extends Migration
{
    public function up(): void
    {
        foreach (TenancySchema::companyTables() as $table) {
            if (TenancySchema::hasColumn($table, 'company_id')) {
                continue;
            }
            $nullable = TenantTables::kind($table) === TenantTables::NULLABLE;
            $position = TenancySchema::hasColumn($table, 'id') ? 'AFTER `id`' : 'FIRST';
            $fk = TenancySchema::name($table.'_company_id_foreign');
            DB::statement("ALTER TABLE `$table` ADD COLUMN `company_id` BIGINT UNSIGNED ".($nullable ? 'NULL' : 'NOT NULL')." DEFAULT 1 $position, ADD CONSTRAINT `$fk` FOREIGN KEY (`company_id`) REFERENCES `companies` (`id`) ON DELETE RESTRICT");
            if ($nullable) {
                // Existing users and audit rows belong to company 1; new platform rows stay NULL.
                DB::statement("ALTER TABLE `$table` ALTER COLUMN `company_id` SET DEFAULT NULL");
            }
        }
        if (! TenancySchema::hasColumn('users', 'is_platform_admin')) {
            DB::statement('ALTER TABLE `users` ADD COLUMN `is_platform_admin` TINYINT(1) NOT NULL DEFAULT 0 AFTER `company_id`');
        }
        if (! TenancySchema::checkExists('users', 'users_platform_admin_company')) {
            DB::statement('ALTER TABLE `users` ADD CONSTRAINT `users_platform_admin_company` CHECK ((`is_platform_admin` = 1 AND `company_id` IS NULL) OR (`is_platform_admin` = 0 AND `company_id` IS NOT NULL))');
        }
        if (! TenancySchema::indexExists('audit_logs', 'audit_logs_company_occurred_index')) {
            DB::statement('ALTER TABLE `audit_logs` ADD INDEX `audit_logs_company_occurred_index` (`company_id`, `occurred_at`)');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Multi-company migrations are not reversible; restore the pre-migration backup.');
    }
};
