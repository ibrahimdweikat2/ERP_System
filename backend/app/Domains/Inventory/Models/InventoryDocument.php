<?php

namespace App\Domains\Inventory\Models;

use App\Domains\Approvals\Models\Approval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

abstract class InventoryDocument extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'gross_value' => 'decimal:4', 'posted_at' => 'datetime', 'snapshot_at' => 'datetime'];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StockLocation::class);
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }
}
