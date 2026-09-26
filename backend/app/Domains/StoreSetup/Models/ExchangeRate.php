<?php

namespace App\Domains\StoreSetup\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rate_to_base' => 'decimal:8', 'locked' => 'boolean'];
    }
}
