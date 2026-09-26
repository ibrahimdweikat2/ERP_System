<?php
namespace App\Domains\Sales\Models;
use Illuminate\Database\Eloquent\Model;
class SalesInvoice extends Model {
    protected $guarded=['id'];
    protected function casts(): array { return ['customer_snapshot'=>'array','checkout'=>'array','warnings'=>'array','version'=>'integer','policy_version'=>'integer','net_total'=>'decimal:4','tax_total'=>'decimal:4','foreign_total'=>'decimal:4','base_total'=>'decimal:4','base_net_total'=>'decimal:4','base_tax_total'=>'decimal:4','cogs_total'=>'decimal:4','exchange_rate'=>'decimal:8']; }
    public function lines(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(SalesInvoiceLine::class)->orderBy('id'); }
}
