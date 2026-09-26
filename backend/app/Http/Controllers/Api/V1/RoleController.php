<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Identity\Models\Permission;
use App\Domains\Identity\Models\Role;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Role::with('permissions')->orderBy('id')->get()]);
    }

    public function permissions(): JsonResponse
    {
        return response()->json(['data' => Permission::orderBy('name')->get()]);
    }

    public function store(Request $r): JsonResponse
    {
        return $this->save($r, new Role);
    }

    public function update(Request $r, Role $role): JsonResponse
    {
        return $this->save($r, $role);
    }

    private function save(Request $r, Role $role): JsonResponse
    {
        abort_if($role->name === 'owner', 422, 'صلاحيات دور المالك ثابتة.');
        $data = $r->validate(['name' => ['required', 'regex:/^[a-z][a-z0-9_]{1,79}$/', Rule::notIn(['owner']), Rule::unique('roles')->ignore($role->id)],
            'label' => ['required', 'string', 'max:120'], 'permission_ids' => ['present', 'array'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id']]);
        // Administrative role editing is owner-only: roles.manage cannot mint arbitrary privileges.
        abort_unless($r->user()->isOwner(), 403, 'تعديل الصلاحيات متاح للمالك فقط.');

        return DB::transaction(function () use ($data, $role) {
            $before = $role->exists ? $role->load('permissions')->toArray() : null;
            $role->fill(['name' => $data['name'], 'label' => $data['label']])->save();
            $role->permissions()->sync($data['permission_ids']);
            app(RecordAudit::class)->execute('roles.saved', 'role', $role->id, $before, $role->fresh('permissions')->toArray());

            return response()->json(['data' => $role->fresh('permissions')]);
        }, 3);
    }
}
