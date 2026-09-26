<?php

namespace App\Domains\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class StockLocation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'sellable' => 'boolean'];
    }
}
