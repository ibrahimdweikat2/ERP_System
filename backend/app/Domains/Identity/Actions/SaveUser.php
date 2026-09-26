<?php

namespace App\Domains\Identity\Actions;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Identity\Models\Role;
use App\Models\User;
use App\Support\BusinessException;
use Illuminate\Support\Facades\DB;

class SaveUser
{
    public function execute(array $data, ?User $existing = null): User
    {
        return DB::transaction(function () use ($data, $existing) {
            // Serialize owner membership changes, including changes to different users.
            $ownerRole = Role::where('name', 'owner')->lockForUpdate()->firstOrFail();
            $user = $existing ? User::with('roles.permissions')->lockForUpdate()->findOrFail($existing->id) : new User;
            $before = $user->exists ? [...$user->only(['name', 'email', 'status', 'phone', 'mfa_required']), 'role_ids' => $user->roles->modelKeys()] : null;
            $roleIds = $data['role_ids'];
            unset($data['role_ids']);
            $actor = auth()->user();
            if ($actor && ! $actor->isOwner()) {
                $granted = Role::with('permissions')->whereIn('id', $roleIds)->get()->flatMap(fn ($r) => $r->permissions->pluck('name'))->unique();
                if ($granted->contains(fn ($p) => ! $actor->hasPermission($p))) {
                    throw new BusinessException('ROLE_ESCALATION_FORBIDDEN', 'لا يمكنك منح صلاحيات لا تملكها.', 403);
                }
            }
            if (in_array($ownerRole->id, $roleIds) && $actor && ! $actor->isOwner()) {
                throw new BusinessException('OWNER_GRANT_FORBIDDEN', 'فقط المالك يستطيع تعيين مالك آخر.', 403);
            }
            if ($user->exists && $user->isOwner() && $actor && ! $actor->isOwner()) {
                throw new BusinessException('OWNER_EDIT_FORBIDDEN', 'فقط المالك يستطيع تعديل حساب مالك.', 403);
            }
            if ($user->exists && $user->isOwner() && ($data['status'] !== 'active' || ! in_array($ownerRole->id, $roleIds))) {
                $others = User::where('id', '!=', $user->id)->where('status', 'active')->whereHas('roles', fn ($q) => $q->where('roles.id', $ownerRole->id))->exists();
                if (! $others) {
                    throw new BusinessException('LAST_OWNER_REQUIRED', 'يجب إبقاء حساب مالك نشط واحد على الأقل.');
                }
            }
            if (empty($data['password'])) {
                unset($data['password']);
            }
            $user->fill($data)->save();
            $user->roles()->sync($roleIds);
            if ($user->status === 'disabled' || isset($data['password'])) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            app(RecordAudit::class)->execute($before ? 'users.updated' : 'users.created', 'user', $user->id, $before, [...$user->only(['name', 'email', 'phone', 'status', 'mfa_required']), 'role_ids' => $roleIds, 'password_changed' => isset($data['password'])]);

            return $user->fresh('roles.permissions');
        }, 3);
    }
}
