<?php

namespace App\Domains\CashBank\Models;

use Illuminate\Database\Eloquent\Model;

class BankAccount extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'account_number' => 'encrypted', 'iban' => 'encrypted'];
    }
}
