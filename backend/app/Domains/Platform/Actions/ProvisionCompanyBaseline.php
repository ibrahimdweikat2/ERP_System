<?php

namespace App\Domains\Platform\Actions;

use App\Domains\Platform\Models\Company;
use App\Support\Tenancy\CompanyContext;
use Database\Seeders\CompanyBaselineSeeder;

/**
 * Gives a company everything it needs to start: the standard roles (same names,
 * labels and permissions as every other company), document sequences, chart of
 * accounts, journals, stock locations, policies and store settings.
 */
class ProvisionCompanyBaseline
{
    public function __construct(private CompanyContext $context) {}

    public function execute(Company $company): void
    {
        $this->context->run($company->id, fn () => (new CompanyBaselineSeeder)->setContainer(app())->__invoke());
    }
}
