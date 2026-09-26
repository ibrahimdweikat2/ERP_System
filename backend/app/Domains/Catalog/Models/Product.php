<?php

namespace App\Domains\Catalog\Models;

use App\Domains\Tax\Models\TaxCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['serial_tracked' => 'boolean', 'active' => 'boolean', 'standard_cost' => 'decimal:4', 'cash_price' => 'decimal:4', 'installment_price' => 'decimal:4', 'minimum_price' => 'decimal:4', 'reorder_level' => 'decimal:4', 'specifications' => 'array'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function warrantyPolicy(): BelongsTo
    {
        return $this->belongsTo(WarrantyPolicy::class);
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }

    public function image(): HasOne
    {
        return $this->hasOne(ProductImage::class);
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class);
    }
}
