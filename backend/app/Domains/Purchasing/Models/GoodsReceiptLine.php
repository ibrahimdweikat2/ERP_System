<?php

namespace App\Domains\Purchasing\Models;

use App\Domains\Inventory\Models\StockLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['product_snapshot' => 'array', 'serials' => 'array', 'quantity' => 'decimal:4', 'unit_cost' => 'decimal:8', 'foreign_value' => 'decimal:4', 'base_value' => 'decimal:4'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }
}
