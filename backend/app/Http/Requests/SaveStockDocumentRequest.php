<?php

namespace App\Http\Requests;

use App\Domains\Inventory\Actions\StockLedger;
use App\Domains\Inventory\Enums\StockDocumentType;
use App\Support\Decimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveStockDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $type = StockDocumentType::fromRoute($this->route('stockKind'));
        $permission = $type === StockDocumentType::Adjustment && $this->input('adjustment_kind') === 'opening' ? 'inventory.receive' : $type->permission();
        if ($type === StockDocumentType::Adjustment && in_array($this->input('adjustment_kind'), ['opening', 'gain'], true) && ! $this->user()?->hasPermission('inventory.view_cost')) {
            return false;
        }

        return $this->user()?->hasPermission($permission) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_array($this->input('lines'))) {
            $lines = $this->input('lines');
            foreach ($lines as &$line) {
                if (! is_array($line)) {
                    continue;
                }foreach (['serials', 'counted_serials'] as $key) {
                    if (isset($line[$key]) && is_array($line[$key])) {
                        $line[$key] = array_map(fn ($s) => is_string($s) ? StockLedger::normalizeSerial($s) : $s, $line[$key]);
                    }
                }
            }$this->merge(['lines' => $lines]);
        }
    }

    public function rules(): array
    {
        $type = StockDocumentType::fromRoute($this->route('stockKind'));
        $count = $type === StockDocumentType::Count;
        $in = $type === StockDocumentType::Adjustment && in_array($this->input('adjustment_kind'), ['opening', 'gain'], true);
        $money = ['required', 'string', Decimal::MONEY_RULE];
        $serial = ['string', 'regex:/^[A-Z0-9][A-Z0-9_.\/-]{0,119}$/', 'distinct:strict'];
        $rules = ['document_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'reason' => ['required', 'string', 'min:5', 'max:1000'], 'location_id' => ['required', 'integer', 'exists:stock_locations,id'], 'version' => [$this->isMethod('PUT') ? 'required' : 'prohibited', 'integer', 'min:1'], 'lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id']];
        if ($type === StockDocumentType::Transfer) {
            $rules['destination_id'] = ['required', 'integer', 'different:location_id', 'exists:stock_locations,id'];
        }
        if ($type === StockDocumentType::Adjustment) {
            $rules['adjustment_kind'] = ['required', Rule::in(['opening', 'gain', 'loss', 'write_off'])];
        }
        if ($count) {
            $rules['lines.*.counted_quantity'] = ['nullable', 'string', Decimal::MONEY_RULE];
            $rules['lines.*.counted_serials'] = ['present', 'array', 'max:1000'];
            $rules['lines.*.counted_serials.*'] = $serial;
        } else {
            $rules['lines.*.quantity'] = $money;
            $rules['lines.*.serials'] = ['present', 'array', 'max:1000'];
            $rules['lines.*.serials.*'] = $serial;
        }
        $rules['lines.*.unit_cost'] = $in ? ($this->user()->hasPermission('inventory.view_cost') ? $money : ['prohibited']) : ['nullable', 'string', Decimal::MONEY_RULE];
        if (! $in && ! $count) {
            $rules['lines.*.unit_cost'] = ['prohibited'];
        }
        if ($count && ! $this->user()->hasPermission('inventory.view_cost')) {
            $rules['lines.*.unit_cost'] = ['prohibited'];
        }

        return $rules;
    }
}
