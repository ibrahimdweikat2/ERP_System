<?php

namespace App\Http\Requests;

use App\Support\Decimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('purchasing.create') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('code'))) {
            $this->merge(['code' => mb_strtoupper(trim($this->input('code')))]);
        }
    }

    public function rules(): array
    {
        return [
            'version' => [$this->route('supplier') ? 'required' : 'prohibited', 'integer', 'min:1'],
            'code' => ['required', 'string', 'regex:/^[A-Z0-9][A-Z0-9_.\/-]{0,39}$/'],
            'legal_name' => ['required', 'string', 'max:180'],
            'trade_name' => ['nullable', 'string', 'max:180'],
            'tax_number' => ['nullable', 'string', 'max:80'],
            'address' => ['nullable', 'string', 'max:500'],
            'contacts' => ['present', 'array', 'max:20'],
            'contacts.*' => ['required', 'array:name,phone,email,title'],
            'contacts.*.name' => ['required', 'string', 'max:120'],
            'contacts.*.phone' => ['nullable', 'string', 'max:40'],
            'contacts.*.email' => ['nullable', 'email', 'max:255'],
            'contacts.*.title' => ['nullable', 'string', 'max:80'],
            'currency' => ['required', 'string', Rule::exists('currencies', 'code')->where('is_active', true)],
            'payment_terms_days' => ['required', 'integer', 'between:0,3650'],
            'credit_limit' => ['required', 'string', Decimal::MONEY_RULE],
            'active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'bank_info' => [$this->user()->hasPermission('purchasing.pay') ? 'sometimes' : 'prohibited', 'nullable', 'array:bank_name,beneficiary,iban,account_number,swift'],
            'bank_info.*' => ['nullable', 'string', 'max:180'],
            'opening_balance' => ['prohibited'],
            'balance' => ['prohibited'],
        ];
    }
}
