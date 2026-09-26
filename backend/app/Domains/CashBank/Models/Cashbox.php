<?php

namespace App\Domains\CashBank\Models;

use Illuminate\Database\Eloquent\Model;

class Cashbox extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
