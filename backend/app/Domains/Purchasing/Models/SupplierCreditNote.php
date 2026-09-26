<?php

namespace App\Domains\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierCreditNote extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['supplier_snapshot' => 'array', 'payload' => 'array', 'amount' => 'decimal:4', 'base_amount' => 'decimal:4', 'exchange_rate' => 'decimal:8', 'version' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierCreditNoteLine::class);
    }
}
