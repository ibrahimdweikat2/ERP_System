<?php

namespace App\Domains\Platform\Actions;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Identity\Models\Role;
use App\Domains\Identity\RoleTemplates;
use App\Domains\Platform\Models\Company;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;

/**
 * Creates an owner account inside a company, on behalf of the platform superadmin
 * (or the provisioning command). Company users' own SaveUser rules do not apply:
 * the superadmin is not a member of the company.
 */
class CreateCompanyOwner
{
    public function __construct(private RecordAudit $audit, private CompanyContext $context) {}

    /** @param array{name: string, email: string, phone?: ?string, password: string, mfa_required?: bool} $data */
    public function execute(Company $company, array $data, ?User $actor = null): User
    {
        $user = $this->context->run($company->id, fn () => DB::transaction(function () use ($company, $data, $actor) {
            // Same lock SaveUser takes, so owner membership changes stay serialized per company.
            $role = Role::where('name', RoleTemplates::OWNER)->lockForUpdate()->firstOrFail();
            $user = new User;
            $user->forceFill(['name' => $data['name'], 'email' => trim($data['email']), 'phone' => $data['phone'] ?? null,
                'password' => $data['password'], 'mfa_required' => (bool) ($data['mfa_required'] ?? false), 'status' => 'active', 'company_id' => $company->id])->save();
            $user->roles()->attach($role);
            $this->audit->execute('users.owner_provisioned', 'user', $user->id, null, ['email' => $user->email, 'by_platform' => $actor !== null], $actor?->id ?? $user->id);

            return $user->load('roles');
        }, 3));
        if ($actor) {
            $this->audit->execute('platform.owner_created', 'company', $company->id, null, ['user_id' => $user->id, 'email' => $user->email], $actor->id);
        }

        return $user;
    }
}
