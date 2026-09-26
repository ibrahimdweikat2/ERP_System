<?php

namespace App\Domains\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

class WarrantyPolicy extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
