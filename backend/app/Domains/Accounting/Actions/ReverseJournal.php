<?php

namespace App\Domains\Accounting\Actions;

use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Audit\Actions\RecordAudit;
use App\Support\BusinessException;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

class ReverseJournal
{
    public function execute(int $id, string $date, string $reason, int $actorId): JournalEntry
    {
        return DB::transaction(function () use ($id, $date, $reason, $actorId) {
            $source = JournalEntry::with('lines')->lockForUpdate()->findOrFail($id);
            if ($source->status !== 'posted') {
                throw new BusinessException('REVERSAL_REQUIRES_POSTED', 'لا يمكن عكس قيد غير مرحّل.');
            }
            if ($source->reference_type !== 'manual' || $source->reversal_of_id) {
                throw new BusinessException('REVERSE_SOURCE_DOCUMENT', 'اعكس المستند الأصلي عبر مساره التشغيلي للحفاظ على تطابق السجلات.');
            }
            $existing = JournalEntry::where('reversal_of_id', $id)->first();
            if ($existing) {
                if ($existing->entry_date !== $date || $existing->reversal_reason !== $reason) {
                    throw new BusinessException('ALREADY_REVERSED', 'تم عكس القيد بالفعل ببيانات مختلفة.', 409);
                }

return $existing->load(['lines.account', 'journal', 'reversal']);
            }
            if ($date < $source->entry_date) {
                throw new BusinessException('REVERSAL_DATE_INVALID', 'تاريخ العكس لا يسبق تاريخ القيد الأصلي.');
            }
            $reversal = JournalEntry::create(['journal_id' => $source->journal_id, 'entry_date' => $date, 'description' => 'عكس '.$source->entry_no.': '.$reason, 'status' => 'draft', 'currency' => $source->currency, 'exchange_rate' => $source->exchange_rate, 'exchange_rate_date' => $source->exchange_rate_date, 'exchange_rate_source' => $source->exchange_rate_source, 'created_by' => $actorId, 'reference_type' => 'reversal', 'reference_id' => $id, 'reversal_of_id' => $id, 'reversal_reason' => $reason]);
            foreach ($source->lines as $line) {
                $reversal->lines()->create(['account_id' => $line->account_id, 'debit' => $line->credit, 'credit' => $line->debit, 'currency' => $line->currency, 'foreign_amount' => Decimal::sub('0', $line->foreign_amount), 'exchange_rate' => $line->exchange_rate, 'memo' => $line->memo]);
            }
            app(PostJournal::class)->execute($reversal->id, $actorId);
            app(RecordAudit::class)->execute('accounting.journal_reversed', 'journal_entry', $id, null, ['reversal_id' => $reversal->id, 'reason' => $reason], $actorId);

            return $reversal->fresh(['lines.account', 'journal', 'reversal']);
        }, 5);
    }
}
