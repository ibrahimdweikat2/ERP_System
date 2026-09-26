<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $r): array
    {
        $data = parent::toArray($r);
        if (isset($data['tax_code'])) {
            $data['tax_code'] = array_intersect_key($data['tax_code'], array_flip(['id', 'code', 'name_ar', 'category', 'rate', 'effective_from', 'effective_to']));
        }
        if (! $r->user()?->hasPermission('inventory.view_cost') && ! $r->user()?->hasPermission('sales.view_cost')) {
            unset($data['standard_cost']);
        }

        $data['image_url'] = $this->image?->url();
        unset($data['image']);

        return $data;
    }
}
