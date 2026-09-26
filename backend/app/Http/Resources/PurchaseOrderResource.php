<?php

namespace App\Http\Resources;

use App\Support\Decimal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $d = parent::toArray($request);
        if (isset($d['approval'])) {
            unset($d['approval']['payload_json'],$d['approval']['payload_hash']);
        }
        unset($d['supplier']);
        if (isset($d['lines'])) {
            $received = DB::table('goods_receipt_lines as l')->join('goods_receipts as r', 'r.id', '=', 'l.goods_receipt_id')->where('r.purchase_order_id', $this->id)->where('r.status', 'posted')->groupBy('l.purchase_order_line_id')->selectRaw('l.purchase_order_line_id, SUM(l.quantity) as quantity')->pluck('quantity', 'purchase_order_line_id');
            foreach ($d['lines'] as &$line) {
                $line['received_quantity'] = Decimal::money($received[$line['id']] ?? '0');
                $line['remaining_quantity'] = Decimal::sub($line['quantity'], $line['received_quantity']);
            }
        }

        return $d;
    }
}
