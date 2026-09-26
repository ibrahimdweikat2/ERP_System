<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class SupplierInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        if (isset($data['lines'])) {
            $receipts = DB::table('goods_receipt_lines as l')->join('goods_receipts as r', 'r.id', '=', 'l.goods_receipt_id')->whereIn('l.id', array_column($data['lines'], 'goods_receipt_line_id'))->get(['l.id', 'r.id as goods_receipt_id', 'r.document_no','l.serials'])->keyBy('id');
            foreach ($data['lines'] as &$line) {
                $receipt = $receipts[$line['goods_receipt_line_id']];
                $line['goods_receipt_id'] = $receipt->goods_receipt_id;
                $line['receipt_document_no'] = $receipt->document_no;
                $line['serials'] = json_decode($receipt->serials,true,512,JSON_THROW_ON_ERROR);
            }
        }

        return $data;
    }
}
