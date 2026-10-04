<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Domains\Identity\RoleTemplates;
use App\Domains\Platform\Actions\CreateCompany;
use App\Domains\Platform\Actions\UpdateCompany;
use App\Domains\Platform\Models\Company;
use App\Http\Controllers\Controller;
use App\Support\PerPage;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Superadmin: the companies on the platform. */
class CompanyController extends Controller
{
    public function index(Request $r, CompanyContext $context): JsonResponse
    {
        $d = $r->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in([Company::ACTIVE, Company::SUSPENDED])]]);
        $page = Company::query()
            ->when($d['search'] ?? null, fn ($q, $s) => $q->where('name', 'like', "%$s%"))
            ->when($d['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')->paginate(PerPage::resolve(20));
        $ids = $page->getCollection()->modelKeys();
        // Counts span several companies; read them unfiltered for just the listed ids.
        [$users, $owners] = $context->bypass(fn () => [
            DB::table('users')->whereIn('company_id', $ids)->groupBy('company_id')->selectRaw('company_id, COUNT(*) AS total')->pluck('total', 'company_id'),
            DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')->where('roles.name', RoleTemplates::OWNER)
                ->whereIn('roles.company_id', $ids)->groupBy('roles.company_id')->selectRaw('roles.company_id, COUNT(*) AS total')->pluck('total', 'company_id'),
        ]);
        $page->through(fn (Company $c) => [...$this->present($c), 'users_count' => (int) ($users[$c->id] ?? 0), 'owners_count' => (int) ($owners[$c->id] ?? 0)]);

        return response()->json($page);
    }

    public function store(Request $r, CreateCompany $action): JsonResponse
    {
        $d = $r->validate(['name' => ['required', 'string', 'max:160', 'unique:companies,name']]);

        return response()->json(['data' => $this->present($action->execute($d['name'], $r->user()))], 201);
    }

    public function show(Company $company, CompanyContext $context): JsonResponse
    {
        $roles = $context->run($company->id, fn () => DB::table('roles')->orderBy('id')->get(['id', 'name', 'label']));

        return response()->json(['data' => [...$this->present($company), 'roles' => $roles]]);
    }

    public function update(Request $r, Company $company, UpdateCompany $action): JsonResponse
    {
        $d = $r->validate(['name' => ['sometimes', 'required', 'string', 'max:160', Rule::unique('companies', 'name')->ignore($company->id)],
            'status' => ['sometimes', 'required', Rule::in([Company::ACTIVE, Company::SUSPENDED])]]);

        return response()->json(['data' => $this->present($action->execute($company, $d, $r->user()))]);
    }

    private function present(Company $company): array
    {
        return $company->only(['id', 'name', 'status', 'created_at', 'updated_at']);
    }
}
