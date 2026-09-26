<?php

namespace App\Domains\Tax\Actions;

use App\Domains\Tax\Models\TaxCode;
use App\Support\BusinessException;
use App\Support\Decimal;
use Brick\Math\RoundingMode;

class CalculateDocumentTax
{
    public function execute(array $line, string $date): array
    {
        $gross = Decimal::mul($line['quantity'], $line['unit_price']);
        $discount = Decimal::money($line['discount_amount']);
        if (Decimal::cmp($discount, $gross) > 0) {
            throw new BusinessException('DISCOUNT_EXCEEDS_LINE', 'خصم السطر أكبر من قيمته.');
        }
        $amount = Decimal::sub($gross, $discount);
        $tax = isset($line['tax_code_id']) ? TaxCode::sharedLock()->findOrFail($line['tax_code_id']) : null;
        if ($tax && ($tax->effective_from > $date || ($tax->effective_to && $tax->effective_to < $date))) {
            throw new BusinessException('TAX_CODE_NOT_EFFECTIVE', 'رمز الضريبة غير صالح في تاريخ المستند.');
        }
        $rate = $tax?->rate ?? '0.0000';
        $fraction = Decimal::of($rate)->dividedBy('100', 8);
        $base = $line['tax_inclusive'] ? (string) Decimal::of($amount)->dividedBy(Decimal::of('1')->plus($fraction), 4, RoundingMode::HALF_UP) : $amount;
        $vat = $line['tax_inclusive'] ? Decimal::sub($amount, $base) : Decimal::money(Decimal::of($base)->multipliedBy($fraction));
        $total = Decimal::add($base, $vat);
        if (Decimal::cmp($total, '99999999999999.9999') > 0) {
            throw new BusinessException('AMOUNT_OUT_OF_RANGE', 'قيمة السطر تتجاوز الحد المسموح.');
        }

        return ['tax_code_id' => $tax?->id, 'tax_snapshot' => $tax?->only(['id', 'code', 'name_ar', 'category', 'rate', 'effective_from', 'effective_to']), 'tax_rate' => $rate, 'tax_inclusive' => (bool) $line['tax_inclusive'], 'taxable_base' => $base, 'tax_amount' => $vat, 'total' => $total];
    }
}
