<?php

namespace App\Http\Requests;

use App\Support\Decimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('catalog.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $sku = mb_strtoupper(trim((string) $this->input('sku')));
        // A new product may leave the SKU blank; SaveProduct then assigns the next one.
        $this->merge(['sku' => $sku === '' ? null : $sku]);
        if (is_array($this->input('barcodes'))) {
            $this->merge(['barcodes' => array_map(fn ($v) => is_string($v) ? mb_strtoupper(trim($v)) : $v, $this->input('barcodes'))]);
        }
    }

    public function rules(): array
    {
        $id = $this->route('product')?->id;
        $money = ['required', 'string', Decimal::MONEY_RULE];
        $canCost = $this->user()->hasPermission('inventory.view_cost') || $this->user()->hasPermission('sales.view_cost');

        return ['sku' => [$id ? 'required' : 'nullable', 'regex:/^[A-Z0-9][A-Z0-9_.\\/-]{0,79}$/', Rule::unique('products')->ignore($id)], 'name_ar' => ['required', 'string', 'max:180'], 'name_en' => ['nullable', 'string', 'max:180'], 'manufacturer_model' => ['nullable', 'string', 'max:120'],
            'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->where('active', true)], 'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('active', true)], 'unit_id' => ['required', 'integer', 'exists:units,id'], 'warranty_policy_id' => ['nullable', 'integer', Rule::exists('warranty_policies', 'id')->where('active', true)], 'tax_code_id' => ['nullable', 'integer', 'exists:tax_codes,id'],
            'serial_tracked' => ['required', 'boolean'], 'standard_cost' => [$canCost ? 'sometimes' : 'prohibited', 'string', Decimal::MONEY_RULE], 'cash_price' => $money, 'installment_price' => $money, 'minimum_price' => $money, 'reorder_level' => $money, 'active' => ['required', 'boolean'],
            'energy_rating' => ['nullable', 'string', 'max:30'], 'country_of_origin' => ['nullable', 'string', 'max:80'], 'specifications' => ['nullable', 'array', 'max:30'], 'specifications.*' => ['required', 'array:name,value'], 'specifications.*.name' => ['required', 'string', 'max:100'], 'specifications.*.value' => ['required', 'string', 'max:300'],
            'barcodes' => ['present', 'array', 'max:20'], 'barcodes.*' => ['required', 'string', 'regex:/^[A-Z0-9_.\\/-]{1,100}$/', 'distinct', Rule::unique('product_barcodes', 'barcode')->where(fn ($q) => $q->where('product_id', '!=', $id ?? 0))],
            'image' => ['prohibited'], 'quantity' => ['prohibited'], 'qty_on_hand' => ['prohibited'], 'average_cost' => ['prohibited']];
    }
}
