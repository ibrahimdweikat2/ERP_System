<?php

namespace App\Domains\Inventory\Enums;

use App\Domains\Inventory\Models\StockAdjustment;
use App\Domains\Inventory\Models\StockCount;
use App\Domains\Inventory\Models\StockTransfer;

enum StockDocumentType: string
{
    case Transfer = 'stock_transfer';
    case Adjustment = 'stock_adjustment';
    case Count = 'stock_count';

    public static function fromRoute(string $kind): self
    {
        return match ($kind) {
            'stock-transfers' => self::Transfer,'stock-adjustments' => self::Adjustment,'stock-counts' => self::Count
        };
    }

    public function model(): string
    {
        return match ($this) {
            self::Transfer => StockTransfer::class,self::Adjustment => StockAdjustment::class,self::Count => StockCount::class
        };
    }

    public function permission(): string
    {
        return match ($this) {
            self::Transfer => 'inventory.transfer',self::Adjustment => 'inventory.adjust',self::Count => 'inventory.count'
        };
    }
}
