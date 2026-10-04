<?php

namespace App\Support\Tenancy;

/**
 * Which tables hold company data. Every table not listed here is a company table:
 * the query grammar adds "company_id = <current company>" to each read and write,
 * and refuses to run without a company context. New tables are company-scoped by default.
 *
 * Kept in code, not config: a stale config cache on a server must never change
 * which tables are filtered.
 */
class TenantTables
{
    public const GLOBAL = 'global';

    public const NULLABLE = 'nullable';

    public const COMPANY = 'company';

    /** Shared by the whole platform; never filtered. */
    public const GLOBAL_TABLES = [
        'migrations', 'companies', 'permissions',
        'sessions', 'password_reset_tokens', 'cache', 'cache_locks',
        'jobs', 'job_batches', 'failed_jobs',
        'queue_probe_runs', 'backup_runs',
    ];

    /** Rows belong to a company, or to the platform when company_id is NULL (superadmins and their audit trail). */
    public const NULLABLE_TABLES = ['users', 'audit_logs'];

    /** MySQL's own schemas; "erp.products" is still the company table products. */
    private const SYSTEM_SCHEMAS = ['information_schema', 'performance_schema', 'mysql', 'sys'];

    /** Splits "table as alias" into [table, alias]. */
    public static function parse(string $from): array
    {
        $parts = preg_split('/\s+as\s+/i', trim($from));
        $table = strtolower(str_replace('`', '', $parts[0]));

        return [$table, str_replace('`', '', $parts[1] ?? $parts[0])];
    }

    public static function kind(string $table): string
    {
        if (str_contains($table, '.')) {
            [$schema, $table] = explode('.', $table, 2);
            if (in_array($schema, self::SYSTEM_SCHEMAS, true)) {
                return self::GLOBAL;
            }
        }
        if (in_array($table, self::GLOBAL_TABLES, true)) {
            return self::GLOBAL;
        }

        return in_array($table, self::NULLABLE_TABLES, true) ? self::NULLABLE : self::COMPANY;
    }
}
