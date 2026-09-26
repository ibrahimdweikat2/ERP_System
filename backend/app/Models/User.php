<?php

namespace App\Models;

use App\Domains\Identity\Models\Role;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $attributes = ['status' => 'active'];

    protected $fillable = ['name', 'email', 'phone', 'password', 'status', 'last_login_at', 'mfa_required'];

    protected $hidden = ['password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes', 'mfa_last_step'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'last_login_at' => 'datetime', 'mfa_secret' => 'encrypted', 'mfa_recovery_codes' => 'encrypted:array', 'mfa_enabled_at' => 'datetime', 'mfa_last_step' => 'integer', 'mfa_required' => 'boolean'];
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function isOwner(): bool
    {
        return $this->roles->contains('name', 'owner');
    }

    public function hasPermission(string $permission): bool
    {
        return $this->status === 'active' && ($this->isOwner() || $this->roles->contains(fn (Role $r) => $r->permissions->contains('name', $permission)));
    }

    public function permissionNames(): array
    {
        return $this->roles->flatMap(fn (Role $r) => $r->permissions->pluck('name'))->unique()->sort()->values()->all();
    }
}
