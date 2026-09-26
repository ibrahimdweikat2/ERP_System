<?php

namespace App\Domains\Documents\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentAttachment extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['stored_path'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'size' => 'integer', 'entity_id' => 'integer'];
    }
}
