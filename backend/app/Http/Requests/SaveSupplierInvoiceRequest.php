<?php

namespace App\Http\Requests;

use App\Domains\Purchasing\Actions\SaveSupplierInvoice;
use App\Support\Decimal;
use Illuminate\Foundation\Http\FormRequest;

class SaveSupplierInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('purchasing.invoice') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('supplier_invoice_no'))) {
            $this->merge(['supplier_invoice_no' => SaveSupplierInvoice::normalizeNumber($this->input('supplier_invoice_no'))]);
        }
    }

    public function rules(): array
    {
        $money = ['required', 'string', Decimal::MONEY_RULE];

        return ['version' => [$this->route('supplierInvoice') ? 'required' : 'prohibited', 'integer', 'min:1'], 'supplier_id' => ['required', 'integer', 'exists:suppliers,id'], 'supplier_invoice_no' => ['required', 'string', 'max:120'], 'invoice_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:posting_date'], 'posting_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:invoice_date'], 'currency' => ['required', 'string', 'size:3'], 'variance_reason' => ['nullable', 'string', 'max:1000'], 'notes' => ['nullable', 'string', 'max:3000'], 'lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*' => ['required', 'array:goods_receipt_line_id,quantity,unit_price,discount_amount,tax_code_id,tax_inclusive,tax_recoverable'], 'lines.*.goods_receipt_line_id' => ['required', 'integer', 'exists:goods_receipt_lines,id'], 'lines.*.quantity' => $money, 'lines.*.unit_price' => $money, 'lines.*.discount_amount' => $money, 'lines.*.tax_code_id' => ['required', 'integer', 'exists:tax_codes,id'], 'lines.*.tax_inclusive' => ['required', 'boolean'], 'lines.*.tax_recoverable' => ['required', 'boolean']];
    }
}
