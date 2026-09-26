<?php

namespace App\Domains\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_control_account' => 'boolean', 'allow_manual_posting' => 'boolean', 'active' => 'boolean'];
    }
}
