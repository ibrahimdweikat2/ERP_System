<?php

namespace App\Domains\StoreSetup\Actions;

use App\Domains\StoreSetup\Models\Currency;
use App\Domains\StoreSetup\Models\ExchangeRate;
use App\Support\BusinessException;

class CurrencySnapshot
{
    public function execute(string $currency, string $date, string $base): array
    {
        if (! Currency::where('code', $currency)->where('is_active', true)->sharedLock()->first()) {
            throw new BusinessException('CURRENCY_INACTIVE', 'العملة غير نشطة.');
        }
        if ($currency === $base) {
            return ['currency' => $currency, 'base_currency' => $base, 'exchange_rate' => '1.00000000', 'exchange_rate_date' => $date, 'exchange_rate_source' => 'base currency'];
        }
        $rate = ExchangeRate::where('currency_code', $currency)->where('rate_date', '<=', $date)->orderByDesc('rate_date')->sharedLock()->first();
        if (! $rate) {
            throw new BusinessException('EXCHANGE_RATE_MISSING', 'أضف سعر صرف في تاريخ المستند أو قبله.');
        }

        return ['currency' => $currency, 'base_currency' => $base, 'exchange_rate' => $rate->rate_to_base, 'exchange_rate_date' => $rate->rate_date, 'exchange_rate_source' => $rate->source];
    }
}
