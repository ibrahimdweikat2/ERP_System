<?php

namespace App\Http\Requests;

use App\Support\Decimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccountingMasterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('settings.manage') ?? false;
    }

    public function rules(): array
    {
        $kind = $this->route('master');
        $id = $this->route('id');
        $money = ['required', 'string', Decimal::RATE_RULE];

        return match ($kind) {
            'currencies' => ['code' => ['required', 'regex:/^[A-Z]{3}$/', Rule::unique('currencies', 'code')->ignore($id, 'code')], 'name' => ['required', 'string', 'max:80'], 'decimal_places' => ['required', 'integer', 'between:0,4'], 'is_active' => ['required', 'boolean']],
            'exchange-rates' => ['currency_code' => ['required', 'exists:currencies,code'], 'rate_date' => ['required', 'date_format:Y-m-d'], 'rate_to_base' => $money, 'source' => ['required', 'string', 'max:160']],
            'accounts' => ['code' => ['required', 'regex:/^[A-Za-z0-9_-]{1,20}$/', Rule::unique('accounts')->ignore($id)], 'name_ar' => ['required', 'string', 'max:160'], 'name_en' => ['nullable', 'string', 'max:160'], 'parent_id' => ['nullable', 'integer', 'exists:accounts,id'], 'account_type' => ['required', Rule::in(['asset', 'liability', 'equity', 'revenue', 'expense'])], 'normal_balance' => ['required', Rule::in(['debit', 'credit'])], 'is_control_account' => ['required', 'boolean'], 'allow_manual_posting' => ['required', 'boolean'], 'active' => ['required', 'boolean']],
            'journals' => ['code' => ['required', 'regex:/^[a-z_]{1,20}$/', Rule::unique('journals')->ignore($id)], 'name_ar' => ['required', 'string', 'max:120'], 'active' => ['required', 'boolean']],
            'tax-codes' => ['code' => ['required', 'regex:/^[A-Za-z0-9_-]{1,30}$/'], 'name_ar' => ['required', 'string', 'max:120'], 'category' => ['required', Rule::in(['standard', 'zero', 'exempt'])], 'rate' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,2})(\\.[0-9]{1,4})?$/'], 'effective_from' => ['required', 'date_format:Y-m-d'], 'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'], 'input_account_id' => ['required', 'integer', 'exists:accounts,id'], 'output_account_id' => ['required', 'integer', 'different:input_account_id', 'exists:accounts,id']],
            'cashboxes' => ['name' => ['required', 'string', 'max:120'], 'account_id' => ['required', 'integer', 'exists:accounts,id', Rule::unique('cashboxes')->ignore($id)], 'currency_code' => ['required', 'exists:currencies,code'], 'active' => ['required', 'boolean']],
            'bank-accounts' => ['name' => ['required', 'string', 'max:120'], 'bank_name' => ['required', 'string', 'max:120'], 'account_number' => ['nullable', 'string', 'max:80'], 'iban' => ['nullable', 'string', 'max:80'], 'account_id' => ['required', 'integer', 'exists:accounts,id', Rule::unique('bank_accounts')->ignore($id)], 'currency_code' => ['required', 'exists:currencies,code'], 'active' => ['required', 'boolean']],
            default => [],
        };
    }
}
