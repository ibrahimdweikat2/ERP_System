<?php
namespace App\Domains\Sales\Models;
use Illuminate\Database\Eloquent\Model;
class SalesInvoiceLine extends Model {
    public $timestamps=false; protected $guarded=['id'];
    protected function casts(): array { return ['product_snapshot'=>'array','serials'=>'array','warranty_snapshot'=>'array','tax_snapshot'=>'array','tax_inclusive'=>'boolean']; }
}
