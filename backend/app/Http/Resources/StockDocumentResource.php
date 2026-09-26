<?php

namespace App\Http\Resources;

use App\Domains\Inventory\Models\StockTransfer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockDocumentResource extends JsonResource
{
    public function toArray(Request $r): array
    {
        if ($this->resource instanceof StockTransfer) {
            $this->resource->loadMissing('destination');
        }
        $d = parent::toArray($r);
        $cost = $r->user()->hasPermission('inventory.view_cost');
        unset($d['approved_payload_hash']);
        foreach ($d['lines'] ?? [] as $i => $line) {
            if (isset($line['product'])) {
                $d['lines'][$i]['product'] = array_intersect_key($line['product'], array_flip(['id', 'sku', 'name_ar', 'serial_tracked', 'unit_id']));
            }
            if (! $cost) {
                unset($d['lines'][$i]['unit_cost'],$d['lines'][$i]['posted_value']);
            }
        }
        if (isset($d['approval'])) {
            unset($d['approval']['payload_json'],$d['approval']['payload_hash']);
            if (! $cost) {
                unset($d['approval']['amount']);
            }
        }
        if (! $cost) {
            unset($d['gross_value']);
        }

        return $d;
    }
}
