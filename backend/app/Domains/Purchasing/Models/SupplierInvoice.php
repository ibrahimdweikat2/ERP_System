<?php

namespace App\Domains\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupplierInvoice extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        $casts = ['supplier_snapshot' => 'array', 'policy_snapshot' => 'array', 'policy_version' => 'integer', 'version' => 'integer', 'exchange_rate' => 'decimal:8', 'posted_at' => 'datetime'];
        foreach (['net_total', 'tax_total', 'foreign_total', 'base_net_total', 'base_tax_total', 'base_total', 'grni_total', 'price_variance_total', 'fx_variance_total'] as $field) {
            $casts[$field] = 'decimal:4';
        }

        return $casts;
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SupplierInvoiceLine::class)->orderBy('id');
    }
}
