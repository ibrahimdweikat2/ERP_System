<?php

namespace App\Domains\Accounting\Actions;

use App\Domains\Accounting\Models\Account;
use App\Domains\Accounting\Models\AccountingPeriod;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\StoreSetup\Actions\NextDocumentNumber;
use App\Support\BusinessException;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

class PostJournal
{
    public function execute(int $id, int $actorId): JournalEntry
    {
        return DB::transaction(function () use ($id, $actorId) {
            $entry = JournalEntry::with(['lines.account', 'journal'])->lockForUpdate()->findOrFail($id);
            if ($entry->status === 'posted') {
                return $entry->load('reversal');
            }
            if ($entry->status !== 'draft' || ! $entry->journal->active) {
                throw new BusinessException('JOURNAL_NOT_POSTABLE', 'القيد غير صالح للترحيل.');
            }
            $period = AccountingPeriod::where('starts_on', '<=', $entry->entry_date)->where('ends_on', '>=', $entry->entry_date)->lockForUpdate()->first();
            if (! $period || $period->status !== 'open') {
                throw new BusinessException('PERIOD_NOT_OPEN', 'تاريخ القيد خارج فترة مالية مفتوحة.');
            }
            if ($entry->lines->count() < 2) {
                throw new BusinessException('JOURNAL_LINES_REQUIRED', 'القيد يتطلب سطرين على الأقل.');
            }
            $debit = '0.0000';
            $credit = '0.0000';
            $accounts = Account::whereIn('id', $entry->lines->pluck('account_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($entry->lines as $line) {
                $line->setRelation('account', $accounts[$line->account_id]);
                if (! $line->account->active) {
                    throw new BusinessException('ACCOUNT_INACTIVE', 'أحد حسابات القيد غير نشط.');
                }
                if ($entry->reference_type === 'manual' && ($line->account->is_control_account || ! $line->account->allow_manual_posting)) {
                    throw new BusinessException('MANUAL_ACCOUNT_FORBIDDEN', 'لا يمكن ترحيل قيد يدوي إلى حساب رقابي.');
                }
                $debit = Decimal::add($debit, $line->debit);
                $credit = Decimal::add($credit, $line->credit);
            }
            if (Decimal::cmp($debit, $credit) !== 0 || Decimal::cmp($debit, '0') <= 0) {
                throw new BusinessException('JOURNAL_UNBALANCED', 'مجموع المدين يجب أن يساوي الدائن تماماً بالعملة الأساسية.');
            }
            $entry->update(['entry_no' => app(NextDocumentNumber::class)->execute('journal_entry', $entry->entry_date), 'fiscal_period_id' => $period->id, 'status' => 'posted', 'posted_at' => now(), 'posted_by' => $actorId]);
            app(\App\Domains\CashBank\Actions\CaptureTreasuryMovements::class)->execute($entry, $actorId);
            app(RecordAudit::class)->execute('accounting.journal_posted', 'journal_entry', $id, null, ['entry_no' => $entry->entry_no, 'debit' => $debit, 'credit' => $credit], $actorId);

            return $entry->fresh(['lines.account', 'journal', 'reversal']);
        }, 5);
    }
}
