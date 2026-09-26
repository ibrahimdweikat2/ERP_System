<?php
namespace App\Domains\Customers\Models;
use Illuminate\Database\Eloquent\Model;
class Customer extends Model {
    protected $guarded=['id']; protected $hidden=['identity_number'];
    protected function casts(): array { return ['identity_number'=>'encrypted','credit_limit'=>'decimal:4','active'=>'boolean','is_walk_in'=>'boolean','version'=>'integer']; }
    public function identity(): array { return $this->only(['id','code','name','phone','tax_number','address','is_walk_in']); }
}
