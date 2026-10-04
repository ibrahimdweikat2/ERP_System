<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domains\Identity\RoleTemplates;
use App\Domains\Platform\Actions\CreateCompanyOwner;
use App\Domains\Platform\Models\Company;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\GloballyUniqueEmail;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/** Superadmin: the owner accounts of one company. */
class CompanyOwnerController extends Controller
{
    private const FIELDS = ['id', 'name', 'email', 'phone', 'status', 'last_login_at', 'mfa_required', 'mfa_enabled_at', 'created_at'];

    public function index(Company $company, CompanyContext $context): JsonResponse
    {
        $owners = $context->run($company->id, fn () => User::whereHas('roles', fn ($q) => $q->where('name', RoleTemplates::OWNER))->orderBy('id')->get(self::FIELDS));

        return response()->json(['data' => $owners->map(fn (User $u) => $this->present($u))]);
    }

    public function store(Request $r, Company $company, CreateCompanyOwner $action): JsonResponse
    {
        $d = $r->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:255', new GloballyUniqueEmail],
            'phone' => ['nullable', 'string', 'max:40'], 'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()],
            'mfa_required' => ['sometimes', 'boolean']]);

        return response()->json(['data' => $this->present($action->execute($company, $d, $r->user()))], 201);
    }

    private function present(User $user): array
    {
        return [...$user->only(['id', 'name', 'email', 'phone', 'status', 'last_login_at', 'created_at']),
            'mfa_required' => (bool) $user->mfa_required, 'mfa_enabled' => (bool) $user->mfa_enabled_at];
    }
}
