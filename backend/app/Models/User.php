<?php

namespace App\Models;

use App\Domains\Identity\Models\Role;
use App\Domains\Platform\Models\Company;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $attributes = ['status' => 'active'];

    protected $fillable = ['name', 'email', 'phone', 'password', 'status', 'last_login_at', 'mfa_required'];

    protected $hidden = ['password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes', 'mfa_last_step'];

    protected static function booted(): void
    {
        // The query grammar also adds company_id, but only in SQL; keep the model in step
        // so code reading $user->company_id right after create() sees the real value.
        static::creating(function (User $user) {
            if (! array_key_exists('company_id', $user->getAttributes()) && ! $user->is_platform_admin) {
                $user->company_id = app(CompanyContext::class)->companyId();
            }
        });
    }

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'last_login_at' => 'datetime', 'mfa_secret' => 'encrypted', 'mfa_recovery_codes' => 'encrypted:array', 'mfa_enabled_at' => 'datetime', 'mfa_last_step' => 'integer', 'mfa_required' => 'boolean', 'company_id' => 'integer', 'is_platform_admin' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_platform_admin;
    }

    public function isOwner(): bool
    {
        return ! $this->isPlatformAdmin() && $this->roles->contains('name', 'owner');
    }

    public function hasPermission(string $permission): bool
    {
        return $this->status === 'active' && ! $this->isPlatformAdmin() && ($this->isOwner() || $this->roles->contains(fn (Role $r) => $r->permissions->contains('name', $permission)));
    }

    public function permissionNames(): array
    {
        return $this->roles->flatMap(fn (Role $r) => $r->permissions->pluck('name'))->unique()->sort()->values()->all();
    }
}
