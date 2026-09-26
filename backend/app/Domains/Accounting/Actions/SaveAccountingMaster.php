<?php

namespace App\Domains\Accounting\Actions;

use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\Journal;
use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\CashBank\Models\BankAccount;
use App\Domains\CashBank\Models\Cashbox;
use App\Domains\StoreSetup\Models\Currency;
use App\Domains\StoreSetup\Models\ExchangeRate;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Domains\Tax\Models\TaxCode;
use App\Support\BusinessException;
use App\Support\Decimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SaveAccountingMaster
{
    public const MODELS = ['currencies' => Currency::class, 'exchange-rates' => ExchangeRate::class, 'accounts' => Account::class, 'journals' => Journal::class, 'tax-codes' => TaxCode::class, 'cashboxes' => Cashbox::class, 'bank-accounts' => BankAccount::class];

    public function execute(string $kind, array $data, string|int|null $id = null): Model
    {
        return DB::transaction(function () use ($kind, $data, $id) {
            $store = StoreSetting::lockForUpdate()->findOrFail(1);
            $class = self::MODELS[$kind] ?? throw new BusinessException('UNKNOWN_MASTER', 'نوع السجل غير معروف.', 404);
            $record = $id !== null ? $class::lockForUpdate()->findOrFail($id) : new $class;
            $before = $record->exists ? $record->toArray() : null;
            if ($kind === 'exchange-rates') {
                if ($id !== null) {
                    throw new BusinessException('RATE_IMMUTABLE', 'أسعار الصرف محفوظة. أضف سعراً بتاريخ جديد.');
                }
                if ($data['currency_code'] === $store->base_currency || Decimal::cmp($data['rate_to_base'], '0') <= 0) {
                    throw new BusinessException('INVALID_EXCHANGE_RATE', 'حدد عملة أجنبية وسعر صرف موجباً.');
                }
                $data['created_by'] = auth()->id();
                $data['created_at'] = now();
            }
            if ($kind === 'currencies' && $id !== null) {
                if ($id === $store->base_currency && ! $data['is_active']) {
                    throw new BusinessException('BASE_CURRENCY_REQUIRED', 'العملة الأساسية يجب أن تبقى نشطة.');
                }
                if ($data['code'] !== $id || ($record->decimal_places !== $data['decimal_places'] && Schema::hasTable('journal_lines') && DB::table('journal_lines')->where('currency', $id)->exists())) {
                    throw new BusinessException('CURRENCY_IN_USE', 'لا يمكن تغيير رمز أو دقة عملة مستخدمة.');
                }
            }
            if ($kind === 'accounts') {
                if ($data['is_control_account'] && $data['allow_manual_posting']) {
                    throw new BusinessException('CONTROL_ACCOUNT_MANUAL_BLOCKED', 'الحساب الرقابي لا يقبل قيوداً يدوية عادية.');
                }
                if ($id !== null) {
                    $parent = $data['parent_id'] ?? null;
                    $seen = [$record->id];
                    while ($parent) {
                        if (in_array($parent, $seen)) {
                            throw new BusinessException('ACCOUNT_PARENT_CYCLE', 'لا يمكن إنشاء حلقة في شجرة الحسابات.');
                        }$seen[] = $parent;
                        $parent = Account::findOrFail($parent)->parent_id;
                    }
                    if (Schema::hasTable('journal_lines') && DB::table('journal_lines')->where('account_id', $id)->exists()) {
                        foreach (['code', 'account_type', 'normal_balance', 'is_control_account', 'allow_manual_posting'] as $field) {
                            if ($data[$field] !== $record->$field) {
                                throw new BusinessException('ACCOUNT_IN_USE', 'لا يمكن تغيير البنية المالية لحساب مستخدم.');
                            }
                        }
                    }
                }
            }
            if ($kind === 'tax-codes') {
                if (Decimal::cmp($data['rate'], '100') > 0 || ($data['category'] !== 'standard' && Decimal::cmp($data['rate'], '0') !== 0)) {
                    throw new BusinessException('INVALID_TAX_RATE', 'راجع نسبة وفئة الضريبة.');
                }
                if ($id !== null) {
                    foreach (['code', 'rate', 'category', 'effective_from', 'input_account_id', 'output_account_id'] as $field) {
                        if ((string) $record->$field !== (string) $data[$field] && ! ($field === 'rate' && Decimal::cmp((string) $record->$field, $data[$field]) === 0)) {
                            throw new BusinessException('TAX_VERSION_IMMUTABLE', 'أضف إصداراً جديداً لتغيير المعالجة الضريبية.');
                        }
                    }
                }
                $q = TaxCode::where('code', $data['code'])->where('effective_from', '<=', $data['effective_to'] ?? '9999-12-31')->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $data['effective_from']));
                if ($id !== null) {
                    $q->where('id', '!=', $id);
                }
                if ($q->exists()) {
                    throw new BusinessException('TAX_PERIOD_OVERLAP', 'تواريخ هذا الرمز الضريبي تتداخل مع إصدار موجود.');
                }
                foreach (['input_account_id', 'output_account_id'] as $field) {
                    $account = Account::findOrFail($data[$field]);
                    if (! $account->active || ! $account->is_control_account) {
                        throw new BusinessException('TAX_ACCOUNT_INVALID', 'اختر حساباً ضريبياً رقابياً نشطاً.');
                    }
                }
            }
            if (in_array($kind, ['cashboxes', 'bank-accounts'])) {
                $account = Account::findOrFail($data['account_id']);
                if (! $account->active || $account->account_type !== 'asset' || $account->is_control_account) {
                    throw new BusinessException('TREASURY_ACCOUNT_INVALID', 'الصندوق أو البنك يتطلب حساب أصل نشطاً غير رقابي.');
                }
                $other = $kind === 'cashboxes' ? 'bank_accounts' : 'cashboxes';
                if (DB::table($other)->where('account_id', $account->id)->exists()) {
                    throw new BusinessException('TREASURY_ACCOUNT_SHARED', 'لا يمكن مشاركة حساب أستاذ بين صندوق وبنك.');
                }
                if ($record->exists && ($record->account_id !== $data['account_id'] || $record->currency_code !== $data['currency_code'])) {
                    throw new BusinessException('TREASURY_LINK_IMMUTABLE', 'أنشئ حساب صندوق أو بنك جديداً لتغيير العملة أو رابط الأستاذ.');
                }
            }
            $record->fill($data)->save();
            app(RecordAudit::class)->execute('accounting.master_saved',$kind,$record->getKey(),$before,$record->toArray());

            return $record;
        }, 3);
    }
}
