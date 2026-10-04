<?php

namespace App\Support\Tenancy;

/**
 * A table holding exactly one row per company (store settings, invoice policy).
 * Locking that row serializes writers within one company without blocking others.
 */
trait CompanySingleton
{
    public static function current(): static
    {
        return static::forCurrentCompany()->firstOrFail();
    }

    public static function lockCurrent(): static
    {
        return static::forCurrentCompany()->lockForUpdate()->firstOrFail();
    }

    public static function sharedCurrent(): static
    {
        return static::forCurrentCompany()->sharedLock()->firstOrFail();
    }

    private static function forCurrentCompany()
    {
        return static::query()->where('company_id', app(CompanyContext::class)->requireCompanyId());
    }
}
