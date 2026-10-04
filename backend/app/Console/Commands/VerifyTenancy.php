<?php

namespace App\Console\Commands;

use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\TenancySchema;
use App\Support\Tenancy\TenantTables;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only check that the database is fully company-scoped. Run after the
 * multi-company migrations and after any migration adding a table.
 */
class VerifyTenancy extends Command
{
    protected $signature = 'erp:tenancy:verify';

    protected $description = 'Verify every company table carries company_id and company-scoped keys';

    public function handle(CompanyContext $context): int
    {
        $problems = $context->bypass(fn () => self::problems());
        foreach ($problems as $problem) {
            $this->error($problem);
        }
        if ($problems !== []) {
            return self::FAILURE;
        }
        $this->info('Company scoping verified: '.count(TenancySchema::companyTables()).' company tables, '.DB::table('companies')->count().' companies.');

        return self::SUCCESS;
    }

    /** @return list<string> */
    public static function problems(): array
    {
        $problems = [];
        foreach (TenancySchema::companyTables() as $table) {
            $column = TenancySchema::column($table, 'company_id');
            $nullable = TenantTables::kind($table) === TenantTables::NULLABLE;
            if (! $column) {
                $problems[] = "$table has no company_id column (add it, or list the table in App\Support\Tenancy\TenantTables).";

                continue;
            }
            if (! $nullable && ($column['is_nullable'] !== 'NO' || $column['column_default'] !== null)) {
                $problems[] = "$table.company_id must be NOT NULL without a default.";
            }
            if (! collect(TenancySchema::referencing('companies'))->contains(fn ($fk) => $fk['table'] === $table && $fk['columns'] === ['company_id'])) {
                $problems[] = "$table.company_id has no foreign key to companies.";
            }
        }
        foreach (TenancySchema::COMPANY_UNIQUES as $table => $columns) {
            $indexes = TenancySchema::uniqueIndexes($table);
            if (! in_array(['company_id', ...$columns], $indexes, true) || in_array($columns, $indexes, true)) {
                $problems[] = "$table: ".implode(',', $columns).' must be unique per company only.';
            }
        }
        foreach (TenancySchema::KEYED as $table => $key) {
            if ((TenancySchema::uniqueIndexes($table)['PRIMARY'] ?? []) !== ['company_id', $key]) {
                $problems[] = "$table primary key must be (company_id, $key).";
            }
            foreach (TenancySchema::referencing($table) as $fk) {
                if ($fk['referenced'] !== ['company_id', $key]) {
                    $problems[] = "{$fk['table']}.{$fk['name']} must reference $table by (company_id, $key).";
                }
            }
        }
        foreach (TenancySchema::SINGLETONS as $table) {
            if (! in_array(['company_id'], TenancySchema::uniqueIndexes($table), true)) {
                $problems[] = "$table must hold one row per company.";
            }
        }
        if (TenancySchema::checkExists('store_settings', 'single_store')) {
            $problems[] = 'store_settings still limits the platform to one store.';
        }
        if (DB::table('companies')->where('id', 1)->doesntExist()) {
            $problems[] = 'Company 1 (the original store) is missing.';
        }

        return $problems;
    }
}
