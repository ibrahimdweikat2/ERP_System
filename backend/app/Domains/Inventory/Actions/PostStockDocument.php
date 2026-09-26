<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Accounting\Actions\PostSystemJournal;
use App\Domains\Accounting\Models\AccountingPeriod;
use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Catalog\Models\Product;
use App\Domains\Inventory\Enums\StockDocumentType;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\Inventory\Models\StockLocation;
use App\Domains\StoreSetup\Actions\NextDocumentNumber;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Support\BusinessException;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

class PostStockDocument
{
    public function execute(StockDocumentType $type, int $id, int $version, int $actorId): InventoryDocument
    {
        return DB::transaction(function () use ($type, $id, $version, $actorId) {
            StoreSetting::sharedLock()->findOrFail(1);
            $class = $type->model();
            $doc = $class::with(['lines', 'approval'])->lockForUpdate()->findOrFail($id);
            if ($doc->version !== $version) {
                throw new BusinessException('DOCUMENT_VERSION_CONFLICT', 'تغير المستند. أعد تحميله.', 409);
            }
            if ($doc->status === 'posted') {
                return $doc->load(['lines.product', 'location']);
            }
            if (($type === StockDocumentType::Transfer && $doc->status !== 'draft') || ($type !== StockDocumentType::Transfer && $doc->status !== 'approved')) {
                throw new BusinessException('STOCK_DOCUMENT_NOT_APPROVED', 'أكمل الموافقة قبل ترحيل المستند.');
            }
            $period = AccountingPeriod::where('starts_on', '<=', $doc->document_date)->where('ends_on', '>=', $doc->document_date)->lockForUpdate()->first();
            if (! $period || $period->status !== 'open') {
                throw new BusinessException('PERIOD_NOT_OPEN', 'التاريخ خارج فترة مالية مفتوحة.');
            }
            $preview = app(PreviewStockDocument::class)->execute($type, $doc);
            if ($type !== StockDocumentType::Transfer) {
                $policy = DB::table('approval_policies')->where('key', 'inventory_adjustment')->sharedLock()->first();
                $required = $doc->adjustment_kind === 'opening' || Decimal::cmp($preview['gross_value'], $policy->threshold) >= 0;
                if (! hash_equals($doc->approved_payload_hash ?? '', $preview['hash']) || ($doc->approval && ($doc->approval->status !== 'approved' || $doc->approval->source_version !== $version || $doc->approval->policy_version !== $policy->version)) || (! $doc->approval && $required)) {
                    throw new BusinessException('APPROVAL_PAYLOAD_CHANGED', 'تغير المستند أو سياسة الموافقة أو تكلفة المخزون. أعد تحريره وإرساله للموافقة.', 409);
                }
            }
            $ledger = app(StockLedger::class);
            $gain = '0.0000';
            $loss = '0.0000';
            $lineValues = [];
            $destination = $type === StockDocumentType::Transfer ? StockLocation::findOrFail($doc->destination_id) : null;
            foreach ($preview['effects'] as $effect) {
                $movementType = match (true) {
                    $type === StockDocumentType::Transfer => 'internal_transfer',$type === StockDocumentType::Count => 'count_'.$effect['event'],$doc->adjustment_kind === 'opening' => 'opening_balance',$doc->adjustment_kind === 'write_off' => 'damaged_write_off',default => 'adjustment_'.$effect['event']
                };
                $movement = $ledger->apply(Product::with('unit')->findOrFail($effect['product_id']), StockLocation::findOrFail($effect['location_id']), $effect['direction'], $effect['quantity'], $effect['direction'] === 'in' ? $effect['value'] : null, $effect['serials'], ['type' => $type->value, 'id' => $id, 'line_id' => $effect['line_id'], 'event' => $effect['event'], 'movement_type' => $movementType, 'date' => $doc->document_date], $actorId, $effect['direction'] === 'out' ? $destination : null, $type === StockDocumentType::Transfer && $effect['direction'] === 'in');
                if (Decimal::cmp($movement->total_cost, $effect['value']) !== 0) {
                    throw new BusinessException('STOCK_VALUE_CHANGED', 'تغيرت قيمة المخزون أثناء الترحيل. أعد المحاولة.', 409);
                }
                if ($effect['direction'] === 'in') {
                    $gain = Decimal::add($gain, $movement->total_cost);
                } else {
                    $loss = Decimal::add($loss, $movement->total_cost);
                }
                $lineValues[$effect['line_id']] = Decimal::add($lineValues[$effect['line_id']] ?? '0', $effect['direction'] === 'in' ? $movement->total_cost : Decimal::money(Decimal::of($movement->total_cost)->negated()));
            }
            $journal = null;
            if ($type !== StockDocumentType::Transfer && (Decimal::cmp($gain, '0') > 0 || Decimal::cmp($loss, '0') > 0)) {
                $offset = $doc->adjustment_kind === 'opening' ? 'opening' : 'inventory_adjustment';
                $journal = app(PostSystemJournal::class)->execute($type->value, $id, 'post', $doc->document_date, $doc->reason, [
                    ['mapping' => 'inventory', 'debit' => $gain, 'credit' => '0'], ['mapping' => $offset, 'debit' => '0', 'credit' => $gain],
                    ['mapping' => $offset, 'debit' => $loss, 'credit' => '0'], ['mapping' => 'inventory', 'debit' => '0', 'credit' => $loss],
                ], $actorId, $offset === 'opening' ? 'opening' : 'inventory');
            }
            foreach ($doc->lines as $line) {
                $line->update(['posted_value' => $lineValues[$line->id] ?? '0']);
            }
            $doc->update(['document_no' => app(NextDocumentNumber::class)->execute($type->value, $doc->document_date), 'status' => 'posted', 'posted_by' => $actorId, 'posted_at' => now(), 'posted_journal_entry_id' => $journal?->id, 'gross_value' => $preview['gross_value']]);
            app(RecordAudit::class)->execute($type->value.'.posted', $type->value, $id, null, ['document_no' => $doc->document_no, 'journal_entry_id' => $journal?->id, 'in_value' => $gain, 'out_value' => $loss, 'movement_count' => count($preview['effects'])], $actorId);

            return $doc->fresh(['lines.product', 'location', 'approval']);
        }, 5);
    }
}
