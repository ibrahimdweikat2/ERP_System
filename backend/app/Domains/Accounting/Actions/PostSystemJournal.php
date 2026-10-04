<?php

namespace App\Domains\Accounting\Actions;

use App\Domains\Accounting\Models\Journal;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Support\BusinessException;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/** Internal domain action; never accept source identity or account mapping from a public posting request. */
class PostSystemJournal
{
    public function execute(string $source, int $sourceId, string $event, string $date, string $description, array $lines, int $actorId, string $journalCode = 'inventory', ?array $currencySnapshot = null): JournalEntry
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('System posting requires the source transaction.');
        }
        $existing = JournalEntry::where(['reference_type' => $source, 'reference_id' => $sourceId, 'reference_event' => $event])->first();
        if ($existing) {
            return app(PostJournal::class)->execute($existing->id, $actorId);
        }
        $currency = $currencySnapshot['currency'] ?? StoreSetting::current()->base_currency;
        $rate = $currencySnapshot['exchange_rate'] ?? '1';
        $entry = JournalEntry::create(['journal_id' => Journal::where('code', $journalCode)->firstOrFail()->id, 'entry_date' => $date, 'reference_type' => $source, 'reference_id' => $sourceId, 'reference_event' => $event, 'description' => $description, 'status' => 'draft', 'currency' => $currency, 'exchange_rate' => $rate, 'exchange_rate_date' => $currencySnapshot['exchange_rate_date'] ?? $date, 'exchange_rate_source' => $currencySnapshot['exchange_rate_source'] ?? 'base currency', 'created_by' => $actorId]);
        foreach ($lines as $line) {
            $account = isset($line['mapping']) ? DB::table('account_mappings')->where('key', $line['mapping'])->value('account_id') : ($line['account_id'] ?? null);
            if (! $account) {
                throw new BusinessException('ACCOUNT_MAPPING_MISSING', 'أكمل ربط الحسابات قبل الترحيل.');
            }
            if (Decimal::cmp($line['debit'], '0') === 0 && Decimal::cmp($line['credit'], '0') === 0) {
                continue;
            }
            if ($currencySnapshot !== null && ! array_key_exists('foreign_amount', $line)) {
                throw new \LogicException('Foreign system journals require explicit original amounts per line.');
            }
            $entry->lines()->create(['account_id' => $account, 'debit' => $line['debit'], 'credit' => $line['credit'], 'currency' => $currency, 'foreign_amount' => $line['foreign_amount'] ?? Decimal::sub($line['debit'], $line['credit']), 'exchange_rate' => $line['exchange_rate'] ?? $rate, 'memo' => $line['memo'] ?? null]);
        }

        return app(PostJournal::class)->execute($entry->id, $actorId);
    }
}
