<?php

namespace App\Domains\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['stored_path'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'updated_at' => 'datetime', 'size' => 'integer', 'product_id' => 'integer'];
    }

    // A host-relative URL: the UI, the dev server and any reverse proxy all serve
    // the image from their own origin, so APP_URL's host and port never leak in.
    public function url(): string
    {
        $url = Storage::disk('public')->url($this->stored_path);

        return parse_url($url, PHP_URL_HOST) === null ? $url : (string) parse_url($url, PHP_URL_PATH);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
