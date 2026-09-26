<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryBalance extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['qty_on_hand' => 'decimal:4', 'qty_reserved' => 'decimal:4', 'qty_available' => 'decimal:4', 'inventory_value' => 'decimal:4', 'average_cost' => 'decimal:8'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }
}
