<?php

namespace App\Domains\Inventory\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends InventoryDocument
{
    protected $with = ['destination'];

    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class);
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class, 'destination_id');
    }
}
