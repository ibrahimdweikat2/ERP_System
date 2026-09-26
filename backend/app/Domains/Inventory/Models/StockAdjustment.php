<?php

namespace App\Domains\Inventory\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class StockAdjustment extends InventoryDocument
{
    public function lines(): HasMany
    {
        return $this->hasMany(StockAdjustmentLine::class);
    }
}
