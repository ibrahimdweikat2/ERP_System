<?php

namespace App\Http\Requests;

use App\Support\Decimal;
use Illuminate\Foundation\Http\FormRequest;

class SaveJournalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('accounting.journal_create') ?? false;
    }

    public function rules(): array
    {
        return ['journal_id' => ['required', 'integer', 'exists:journals,id'], 'entry_date' => ['required', 'date_format:Y-m-d'], 'description' => ['required', 'string', 'max:1000'], 'currency' => ['required', 'exists:currencies,code'], 'lines' => ['required', 'array', 'min:2', 'max:100'], 'lines.*.account_id' => ['required', 'integer', 'exists:accounts,id'], 'lines.*.debit' => ['required', 'string', Decimal::MONEY_RULE], 'lines.*.credit' => ['required', 'string', Decimal::MONEY_RULE], 'lines.*.memo' => ['nullable', 'string', 'max:500']];
    }
}
