<?php

namespace App\Domains\Purchasing\Models;

use App\Domains\Approvals\Models\Approval;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['approved_payload_hash'];

    protected function casts(): array
    {
        return ['supplier_snapshot' => 'array', 'version' => 'integer', 'approval_policy_version' => 'integer', 'exchange_rate' => 'decimal:8', 'subtotal' => 'decimal:4', 'tax_total' => 'decimal:4', 'total' => 'decimal:4', 'base_total' => 'decimal:4', 'issued_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }
}
