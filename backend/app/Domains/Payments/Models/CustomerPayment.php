<?php
namespace App\Domains\Payments\Models;
use Illuminate\Database\Eloquent\Model;
class CustomerPayment extends Model {
    protected $guarded=['id'];
    protected function casts(): array { return ['customer_snapshot'=>'array','allocation_request'=>'array','amount'=>'decimal:4','base_amount'=>'decimal:4','exchange_rate'=>'decimal:8']; }
    public function allocations(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(PaymentAllocation::class); }
}
