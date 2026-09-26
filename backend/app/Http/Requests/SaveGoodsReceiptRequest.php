<?php

namespace App\Http\Requests;

use App\Domains\Inventory\Actions\StockLedger;
use App\Support\Decimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveGoodsReceiptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('purchasing.receive') && ($this->input('purchase_order_id') || $this->user()->hasPermission('purchasing.receive_without_po'));
    }

    protected function prepareForValidation(): void
    {
        if (! is_array($this->input('lines'))) {
            return;
        }
        $lines = $this->input('lines');
        foreach ($lines as &$line) {
            if (is_array($line) && isset($line['serials']) && is_array($line['serials'])) {
                $line['serials'] = array_map(fn ($s) => is_string($s) ? StockLedger::normalizeSerial($s) : $s, $line['serials']);
            }
        }
        $this->merge(['lines' => $lines]);
    }

    public function rules(): array
    {
        $money = ['required', 'string', Decimal::MONEY_RULE];

        return ['version' => [$this->route('goodsReceipt') ? 'required' : 'prohibited', 'integer', 'min:1'], 'document_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'supplier_id' => ['required', 'integer', 'exists:suppliers,id'], 'purchase_order_id' => ['nullable', 'integer', 'exists:purchase_orders,id'], 'delivery_reference' => ['required', 'string', 'max:120'], 'currency' => ['required', 'string', 'size:3'], 'notes' => ['nullable', 'string', 'max:3000'], 'lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*' => ['required', 'array:purchase_order_line_id,product_id,location_id,condition,condition_notes,quantity,unit_cost,serials'], 'lines.*.purchase_order_line_id' => [$this->input('purchase_order_id') ? 'required' : 'prohibited', 'integer', 'exists:purchase_order_lines,id'], 'lines.*.product_id' => ['required', 'integer', 'exists:products,id'], 'lines.*.location_id' => ['required', 'integer', 'exists:stock_locations,id'], 'lines.*.condition' => ['required', Rule::in(['new', 'open_box', 'damaged'])], 'lines.*.condition_notes' => ['nullable', 'required_unless:lines.*.condition,new', 'string', 'max:500'], 'lines.*.quantity' => $money, 'lines.*.unit_cost' => $this->input('purchase_order_id') ? ['prohibited'] : $money, 'lines.*.serials' => ['present', 'array', 'max:1000'], 'lines.*.serials.*' => ['string', 'regex:/^[A-Z0-9][A-Z0-9_.\/-]{0,119}$/', 'distinct:strict']];
    }
}
