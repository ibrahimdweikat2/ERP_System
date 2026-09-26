<?php

namespace App\Domains\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['bank_info'];

    protected function casts(): array
    {
        return ['contacts' => 'array', 'bank_info' => 'encrypted:array', 'credit_limit' => 'decimal:4', 'payment_terms_days' => 'integer', 'active' => 'boolean', 'version' => 'integer'];
    }

    public function documentIdentity(): array
    {
        return $this->only(['id', 'code', 'legal_name', 'trade_name', 'tax_number', 'address', 'contacts']);
    }
}
