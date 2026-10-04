<?php

namespace App\Domains\Platform\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A tenant of the platform. Every company table row belongs to exactly one company. */
class Company extends Model
{
    public const ACTIVE = 'active';

    public const SUSPENDED = 'suspended';

    protected $fillable = ['name', 'status', 'created_by'];

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
