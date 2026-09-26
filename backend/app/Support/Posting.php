<?php

namespace App\Support;

use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\AccountingPeriod;
use App\Domains\CashBank\Models\BankAccount;
use App\Domains\CashBank\Models\Cashbox;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

final class Posting
{
    public static function period(string $date): void
    {
        $period = AccountingPeriod::where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->lockForUpdate()->first();
        if (! $period || $period->status !== 'open') {
            throw new BusinessException('PERIOD_NOT_OPEN', 'اختر تاريخاً ضمن فترة مالية مفتوحة.');
        }
    }

    public static function account(string $mapping, ?string $type = null): int
    {
        $id = DB::table('account_mappings')->where('key', $mapping)->value('account_id');
        $account = $id ? Account::sharedLock()->find($id) : null;
        if (! $account || ! $account->active || ($type && $account->account_type !== $type)) {
            throw new BusinessException('ACCOUNT_MAPPING_INVALID', 'راجع ربط الحساب المحاسبي: '.$mapping);
        }

        return $account->id;
    }

    public static function treasury(string $method, ?int $id, string $currency): int
    {
        if(!$id)throw new BusinessException('TREASURY_REQUIRED','حدد الصندوق أو الحساب البنكي للدفع.');
        $master = match ($method) {
            'cash' => Cashbox::lockForUpdate()->findOrFail($id),
            'bank' => BankAccount::lockForUpdate()->findOrFail($id),
            default => throw new BusinessException('PAYMENT_METHOD_INVALID', 'اختر الصندوق أو التحويل البنكي.'),
        };
        $account = Account::sharedLock()->findOrFail($master->account_id);
        if (! $master->active || $master->currency_code !== $currency || ! $account->active || $account->account_type !== 'asset' || $account->is_control_account) {
            throw new BusinessException('TREASURY_ACCOUNT_INVALID', 'حساب الدفع غير نشط أو عملته لا تطابق المستند.');
        }

        return $account->id;
    }

    /** Signed debit in base and original currency; negative values are credits. */
    public static function line(int|string $account, string $base, ?string $foreign = null, ?string $rate = null): array
    {
        $line = [is_int($account) ? 'account_id' : 'mapping' => $account,
            'debit' => Decimal::cmp($base, '0') > 0 ? $base : '0.0000',
            'credit' => Decimal::cmp($base, '0') < 0 ? Decimal::sub('0', $base) : '0.0000',
            'foreign_amount' => $foreign ?? $base];
        if ($rate !== null) {
            $line['exchange_rate'] = $rate;
        }

        return $line;
    }

    /** Cumulative proportional allocation consumes the final rounding remainder exactly. */
    public static function portion(string $total, string $whole, string $alreadyQuantity, string $quantity, string $alreadyValue): string
    {
        $next = Decimal::add($alreadyQuantity, $quantity);
        if (Decimal::cmp($quantity, '0') <= 0 || Decimal::cmp($whole, '0') <= 0 || Decimal::cmp($next, $whole) > 0) {
            throw new BusinessException('ALLOCATION_EXCEEDS_BALANCE', 'المبلغ أو الكمية يتجاوز الرصيد المتبقي.');
        }
        $cumulative = Decimal::cmp($next, $whole) === 0 ? $total : (string) Decimal::of($total)->multipliedBy($next)->dividedBy($whole, 4, RoundingMode::HALF_UP);

        return Decimal::sub($cumulative, $alreadyValue);
    }
}
