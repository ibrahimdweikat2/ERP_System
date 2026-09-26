<?php

namespace App\Domains\Inventory\Actions;

use App\Domains\Approvals\Actions\OwnerAutoApproval;
use App\Domains\Approvals\Models\Approval;
use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Inventory\Enums\StockDocumentType;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Support\BusinessException;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

class SubmitStockDocument
{
    public function execute(StockDocumentType $type, int $id, int $version, int $actorId): InventoryDocument
    {
        return DB::transaction(function () use ($type, $id, $version, $actorId) {
            $store = StoreSetting::sharedLock()->findOrFail(1);
            $class = $type->model();
            $doc = $class::with('lines')->lockForUpdate()->findOrFail($id);
            if ($type === StockDocumentType::Transfer) {
                throw new BusinessException('TRANSFER_NO_APPROVAL', 'التحويل الداخلي يرحّل مباشرة بصلاحيته.');
            }
            if ($doc->version !== $version) {
                throw new BusinessException('DOCUMENT_VERSION_CONFLICT', 'تغير المستند. أعد تحميله.', 409);
            }
            if (in_array($doc->status, ['pending', 'approved'], true)) {
                return $doc->load('approval');
            }
            if ($doc->status !== 'draft') {
                throw new BusinessException('STOCK_DOCUMENT_NOT_DRAFT', 'أعد تحرير المستند قبل إرساله للموافقة.');
            }
            $preview = app(PreviewStockDocument::class)->execute($type, $doc);
            $policy = DB::table('approval_policies')->where('key', 'inventory_adjustment')->sharedLock()->first();
            $required = $doc->adjustment_kind === 'opening' || Decimal::cmp($preview['gross_value'], $policy->threshold) >= 0;
            $approval = null;
            if ($required) {
                $approval = Approval::create(['source_type' => $type->value, 'source_id' => $id, 'source_version' => $version, 'payload_hash' => $preview['hash'], 'payload_json' => $preview['payload'], 'amount' => $preview['gross_value'], 'currency' => $store->base_currency, 'policy_key' => $policy->key, 'policy_version' => $policy->version, 'segregate_requester' => $policy->segregate_requester, 'status' => 'pending', 'requested_by' => $actorId]);
            }
            $pending = $required && ! app(OwnerAutoApproval::class)->apply($approval, $actorId);
            $doc->update(['status' => $pending ? 'pending' : 'approved', 'approval_id' => $approval?->id, 'approved_payload_hash' => $preview['hash'], 'gross_value' => $preview['gross_value']]);
            app(RecordAudit::class)->execute($type->value.'.submitted', $type->value, $id, null, ['version' => $version, 'approval_id' => $approval?->id, 'policy_version' => $policy->version, 'threshold' => $policy->threshold, 'gross_value' => $preview['gross_value'], 'automatic' => ! $required], $actorId);

            return $doc->load(['lines.product', 'location', 'approval']);
        }, 5);
    }
}
