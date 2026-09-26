<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        if ($request->user()->hasPermission('purchasing.pay')) {
            $data['bank_info'] = $this->resource->bank_info;
        }

        return $data;
    }
}
