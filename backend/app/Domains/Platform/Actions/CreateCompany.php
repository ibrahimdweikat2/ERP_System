<?php

namespace App\Domains\Platform\Actions;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Platform\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;

/** Creates a company and its baseline in one transaction: a company is never half set up. */
class CreateCompany
{
    public function __construct(private ProvisionCompanyBaseline $baseline, private RecordAudit $audit, private CompanyContext $context) {}

    public function execute(string $name, User $actor): Company
    {
        return DB::transaction(function () use ($name, $actor) {
            $company = Company::create(['name' => trim($name), 'status' => Company::ACTIVE, 'created_by' => $actor->id]);
            $this->baseline->execute($company);
            $this->context->run($company->id, fn () => $this->audit->execute('company.created', 'company', $company->id, null, ['name' => $company->name], $actor->id));
            $this->audit->execute('platform.company_created', 'company', $company->id, null, ['name' => $company->name], $actor->id);

            return $company;
        }, 3);
    }
}
