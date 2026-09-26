<?php

namespace App\Domains\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierInvoiceLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        $casts = ['product_snapshot' => 'array', 'tax_snapshot' => 'array', 'tax_recoverable' => 'boolean', 'tax_inclusive' => 'boolean', 'tax_rate' => 'decimal:4', 'receipt_exchange_rate' => 'decimal:8'];
        foreach (['quantity', 'unit_price', 'discount_amount', 'taxable_base', 'tax_amount', 'total', 'base_net', 'base_tax', 'base_total', 'receipt_foreign_value', 'receipt_base_value', 'price_variance', 'fx_variance'] as $field) {
            $casts[$field] = 'decimal:4';
        }

        return $casts;
    }
}
