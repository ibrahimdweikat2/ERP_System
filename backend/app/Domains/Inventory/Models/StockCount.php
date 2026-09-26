<?php

namespace App\Domains\Inventory\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class StockCount extends InventoryDocument
{
    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class);
    }
}
