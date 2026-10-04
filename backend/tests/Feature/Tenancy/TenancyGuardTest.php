<?php

namespace Tests\Feature\Tenancy;

use App\Console\Commands\VerifyTenancy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Keeps the company filter complete as the code grows: every new table carries
 * company_id, and no code path uses SQL the query grammar cannot filter.
 */
class TenancyGuardTest extends TestCase
{
    use RefreshDatabase;

    /** Files allowed to switch the filter off, each for a reason documented at the call. */
    private const BYPASS_ALLOWED = [
        'app/Console/Commands/VerifyTenancy.php', 'app/Domains/Reporting/Jobs/GenerateReportExport.php',
        'app/Http/Controllers/Api/V1/AuthController.php', 'app/Http/Controllers/Api/V1/Platform/CompanyController.php',
        'app/Rules/GloballyUniqueEmail.php', 'app/Support/Tenancy/CompanyAwareUserProvider.php',
        'app/Support/Tenancy/CompanyContext.php', 'routes/maintenance.php',
    ];

    /** Raw SQL and builder features the company filter cannot see into. */
    private const FORBIDDEN = [
        '/DB::(select|selectOne|scalar|statement|unprepared|insert|update|delete|affectingStatement)\(/' => 'raw SQL statement',
        '/fromRaw\(|DB::table\(\s*DB::raw/' => 'raw FROM clause',
        '/rightJoin\(/' => 'right join',
        '/->truncate\(/' => 'truncate',
        '/insert(OrIgnore)?Using\(/' => 'insert ... select',
        '/(StoreSetting|PurchasingInvoicePolicy)::(\w+\(\)->)*find(OrFail)?\(1\)/' => 'single-store row id 1',
        '/(whereRaw|selectRaw|havingRaw|orderByRaw|groupByRaw)\(\s*[\'"][^\'"]*\b(from|join)\b/i' => 'table inside a raw SQL fragment',
    ];

    public function test_every_company_table_is_scoped_in_the_schema(): void
    {
        $this->assertSame([], $this->companyContext()->bypass(fn () => VerifyTenancy::problems()));
    }

    public function test_code_uses_only_sql_the_company_filter_understands(): void
    {
        $root = base_path();
        $violations = [];
        foreach (['app', 'routes'] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$dir")) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                $code = file_get_contents($file->getPathname());
                foreach (self::FORBIDDEN as $pattern => $what) {
                    if (preg_match($pattern, $code)) {
                        $violations[] = "$relative: $what";
                    }
                }
                if (str_contains($code, 'bypass(') && ! in_array($relative, self::BYPASS_ALLOWED, true)) {
                    $violations[] = "$relative: unfiltered access (bypass) outside the reviewed list";
                }
            }
        }
        $this->assertSame([], $violations);
    }
}
