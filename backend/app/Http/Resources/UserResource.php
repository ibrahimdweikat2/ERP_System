<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'email' => $this->email,
            'phone' => $this->phone, 'status' => $this->status,
            'roles' => $this->roles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'label' => $r->label]),
            'permissions' => $this->permissionNames(), 'is_owner' => $this->isOwner(), 'mfa_required' => (bool) $this->mfa_required, 'mfa_enabled' => (bool) $this->mfa_enabled_at, 'last_login_at' => $this->last_login_at];
    }
}
