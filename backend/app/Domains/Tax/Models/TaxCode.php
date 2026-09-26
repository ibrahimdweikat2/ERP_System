<?php

namespace App\Domains\Tax\Models;

use Illuminate\Database\Eloquent\Model;

class TaxCode extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:4'];
    }
}
