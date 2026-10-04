<?php

namespace App\Support\Tenancy;

class TenantTables
{
    public const GLOBAL = 'global';

    public const NULLABLE = 'nullable';

    public const COMPANY = 'company';

    /** Splits "table as alias" into [table, alias]. */
    public static function parse(string $from): array
    {
        $parts = preg_split('/\s+as\s+/i', trim($from));
        $table = strtolower(str_replace('`', '', $parts[0]));

        return [$table, str_replace('`', '', $parts[1] ?? $parts[0])];
    }

    /** MySQL's own schemas; "erp.products" is still the company table products. */
    private const SYSTEM_SCHEMAS = ['information_schema', 'performance_schema', 'mysql', 'sys'];

    public static function kind(string $table): string
    {
        if (str_contains($table, '.')) {
            [$schema, $table] = explode('.', $table, 2);
            if (in_array($schema, self::SYSTEM_SCHEMAS, true)) {
                return self::GLOBAL;
            }
        }
        if (in_array($table, config('tenancy.global', []), true)) {
            return self::GLOBAL;
        }

        return in_array($table, config('tenancy.nullable', []), true) ? self::NULLABLE : self::COMPANY;
    }
}
