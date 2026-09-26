<?php

namespace App\Domains\Approvals\Models;

use Illuminate\Database\Eloquent\Model;

class Approval extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payload_json' => 'array', 'amount' => 'decimal:4', 'segregate_requester' => 'boolean'];
    }
}
