<?php
namespace App\Domains\Sales\Models;
use Illuminate\Database\Eloquent\Model;
class SalesReturn extends Model {
    protected $guarded=['id'];
    protected function casts(): array { return ['customer_snapshot'=>'array','payload'=>'array','amount'=>'decimal:4','base_amount'=>'decimal:4','exchange_rate'=>'decimal:8']; }
    public function lines(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(SalesReturnLine::class); }
}
