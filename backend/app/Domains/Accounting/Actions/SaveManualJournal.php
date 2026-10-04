<?php

namespace App\Domains\Accounting\Actions;

use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\Journal;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\StoreSetup\Models\Currency;
use App\Domains\StoreSetup\Models\ExchangeRate;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Support\BusinessException;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

class SaveManualJournal
{
    public function execute(array $data, int $actorId, ?int $id = null): JournalEntry
    {
        return DB::transaction(function () use ($data, $actorId, $id) {
            $store = StoreSetting::lockCurrent();
            $entry = $id ? JournalEntry::lockForUpdate()->findOrFail($id) : new JournalEntry;
            if ($entry->exists && ($entry->status !== 'draft' || $entry->reference_type !== 'manual')) {
                throw new BusinessException('JOURNAL_IMMUTABLE', 'لا يمكن تعديل قيد مرحّل. استخدم العكس.');
            }
            $journal = Journal::findOrFail($data['journal_id']);
            if (! $journal->active || $journal->code !== 'general') {
                throw new BusinessException('MANUAL_JOURNAL_BOOK_REQUIRED', 'القيد اليدوي يستخدم دفتر اليومية العامة.');
            }
            if (! Currency::where('code', $data['currency'])->where('is_active', true)->exists()) {
                throw new BusinessException('CURRENCY_INACTIVE', 'العملة غير نشطة.');
            }
            $rate = '1.00000000';
            $rateDate = $data['entry_date'];
            $source = 'base currency';
            if ($data['currency'] !== $store->base_currency) {
                $fx = ExchangeRate::where('currency_code', $data['currency'])->where('rate_date', '<=', $data['entry_date'])->orderByDesc('rate_date')->first();
                if (! $fx) {
                    throw new BusinessException('EXCHANGE_RATE_MISSING', 'أضف سعر صرف صالحاً في تاريخ القيد أو قبله.');
                }
                $rate = $fx->rate_to_base;
                $rateDate = $fx->rate_date;
                $source = $fx->source;
            }
            $before = $entry->exists ? $entry->load('lines')->toArray() : null;
            $entry->fill(['journal_id' => $journal->id, 'entry_date' => $data['entry_date'], 'description' => $data['description'], 'currency' => $data['currency'], 'exchange_rate' => $rate, 'exchange_rate_date' => $rateDate, 'exchange_rate_source' => $source, 'created_by' => $entry->created_by ?? $actorId, 'reference_type' => 'manual', 'status' => 'draft'])->save();
            $entry->lines()->delete();
            foreach ($data['lines'] as $line) {
                $account = Account::findOrFail($line['account_id']);
                if (! $account->active || $account->is_control_account || ! $account->allow_manual_posting) {
                    throw new BusinessException('MANUAL_ACCOUNT_FORBIDDEN', 'هذا الحساب لا يقبل قيوداً يدوية عادية.');
                }
                $debit = Decimal::money($line['debit']);
                $credit = Decimal::money($line['credit']);
                if ((Decimal::cmp($debit, '0') > 0) === (Decimal::cmp($credit, '0') > 0)) {
                    throw new BusinessException('INVALID_JOURNAL_LINE', 'يجب أن يحتوي السطر على مبلغ مدين أو دائن موجب واحد.');
                }
                $baseAmount = Decimal::mul(Decimal::add($debit, $credit), $rate);
                if (Decimal::cmp($baseAmount, '0') <= 0 || Decimal::cmp($baseAmount, '99999999999999.9999') > 0) {
                    throw new BusinessException('BASE_AMOUNT_OUT_OF_RANGE', 'المبلغ بعد التحويل أصغر أو أكبر من الدقة المدعومة.');
                }
                $entry->lines()->create(['account_id' => $account->id, 'debit' => Decimal::mul($debit, $rate), 'credit' => Decimal::mul($credit, $rate), 'currency' => $entry->currency, 'foreign_amount' => Decimal::sub($debit, $credit), 'exchange_rate' => $rate, 'memo' => $line['memo'] ?? null]);
            }
            app(RecordAudit::class)->execute($id ? 'accounting.draft_updated' : 'accounting.draft_created', 'journal_entry', $entry->id, $before, $entry->fresh('lines')->toArray(), $actorId);

            return $entry->fresh(['lines.account', 'journal', 'reversal']);
        }, 3);
    }
}
