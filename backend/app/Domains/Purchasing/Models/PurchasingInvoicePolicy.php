<?php

namespace App\Domains\Purchasing\Models;

use App\Support\Tenancy\CompanySingleton;
use Illuminate\Database\Eloquent\Model;

class PurchasingInvoicePolicy extends Model
{
    use CompanySingleton;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'require_attachment' => 'boolean'];
    }
}
