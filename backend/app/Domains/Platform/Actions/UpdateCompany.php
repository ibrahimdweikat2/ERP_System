<?php

namespace App\Domains\Platform\Actions;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Platform\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;

/** Renames, suspends or reactivates a company. Suspension signs its users out at once. */
class UpdateCompany
{
    public function __construct(private RecordAudit $audit, private CompanyContext $context) {}

    /** @param array{name?: string, status?: string} $data */
    public function execute(Company $company, array $data, User $actor): Company
    {
        return DB::transaction(function () use ($company, $data, $actor) {
            $company = Company::lockForUpdate()->findOrFail($company->id);
            $before = $company->only(['name', 'status']);
            $company->fill(array_intersect_key($data, array_flip(['name', 'status'])))->save();
            if ($before['status'] !== Company::SUSPENDED && $company->status === Company::SUSPENDED) {
                $userIds = $this->context->run($company->id, fn () => User::pluck('id'));
                DB::table('sessions')->whereIn('user_id', $userIds)->delete();
            }
            $this->audit->execute('platform.company_updated', 'company', $company->id, $before, $company->only(['name', 'status']), $actor->id);

            return $company;
        }, 3);
    }
}
