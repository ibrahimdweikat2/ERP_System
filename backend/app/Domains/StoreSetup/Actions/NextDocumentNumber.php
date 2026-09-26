<?php

namespace App\Domains\StoreSetup\Actions;

use App\Domains\StoreSetup\Models\DocumentSequence;
use App\Support\BusinessException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class NextDocumentNumber
{
    public function execute(string $type, string $date): string
    {
        return DB::transaction(function () use ($type, $date) {
            $sequence = DocumentSequence::where('document_type', $type)->lockForUpdate()->first();
            if (! $sequence) {
                throw new BusinessException('SEQUENCE_NOT_CONFIGURED', 'تسلسل المستند غير مهيأ.');
            }
            $year = CarbonImmutable::createFromFormat('!Y-m-d', $date)->format('Y');
            $period = $sequence->reset_policy === 'yearly' ? $year : 'all';
            // Earlier reads in the posting transaction may have established an old
            // repeatable-read snapshot before this sequence lock became available.
            $counter = DB::table('document_sequence_counters')->where('document_sequence_id', $sequence->id)->where('period_key', $period)->lockForUpdate()->first();
            $number = $counter?->next_number ?? $sequence->next_number;
            if ($number > 999999999999) {
                throw new BusinessException('SEQUENCE_EXHAUSTED', 'وصل تسلسل المستند إلى الحد المسموح.');
            }
            // Use the locked row's existence: updateOrInsert performs another
            // non-locking existence query against the older transaction snapshot.
            $counters = DB::table('document_sequence_counters');
            if ($counter) {
                $counters->where('document_sequence_id', $sequence->id)->where('period_key', $period)->update(['next_number' => $number + 1]);
            } else {
                $counters->insert(['document_sequence_id' => $sequence->id, 'period_key' => $period, 'next_number' => $number + 1]);
            }
            $yearSegment = $sequence->reset_policy === 'yearly' ? ($year.'/') : '';

            return $sequence->prefix.$yearSegment.str_pad((string) $number, $sequence->padding, '0', STR_PAD_LEFT);
        }, 5);
    }
}
