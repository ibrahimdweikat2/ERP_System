<?php

namespace App\Domains\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalYear extends Model
{
    protected $guarded = ['id'];

    public function periods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class);
    }
}
