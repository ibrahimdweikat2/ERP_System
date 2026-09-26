<?php

namespace App\Domains\Purchasing\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['product_snapshot' => 'array', 'tax_snapshot' => 'array', 'quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'discount_amount' => 'decimal:4', 'tax_rate' => 'decimal:4', 'tax_inclusive' => 'boolean', 'taxable_base' => 'decimal:4', 'tax_amount' => 'decimal:4', 'total' => 'decimal:4'];
    }
}
