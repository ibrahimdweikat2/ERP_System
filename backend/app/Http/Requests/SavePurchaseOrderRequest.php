<?php

namespace App\Http\Requests;

use App\Support\Decimal;
use Illuminate\Foundation\Http\FormRequest;

class SavePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('purchasing.create') ?? false;
    }

    public function rules(): array
    {
        $money = ['required', 'string', Decimal::MONEY_RULE];

        return ['version' => [$this->route('purchaseOrder') ? 'required' : 'prohibited', 'integer', 'min:1'], 'document_date' => ['required', 'date_format:Y-m-d'], 'expected_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:document_date'], 'supplier_id' => ['required', 'integer', 'exists:suppliers,id'], 'supplier_reference' => ['nullable', 'string', 'max:100'], 'currency' => ['required', 'string', 'size:3'], 'notes' => ['nullable', 'string', 'max:3000'], 'lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*' => ['required', 'array:product_id,quantity,unit_price,discount_amount,tax_code_id,tax_inclusive'], 'lines.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'], 'lines.*.quantity' => $money, 'lines.*.unit_price' => $money, 'lines.*.discount_amount' => $money, 'lines.*.tax_code_id' => ['nullable', 'integer', 'exists:tax_codes,id'], 'lines.*.tax_inclusive' => ['required', 'boolean']];
    }
}
