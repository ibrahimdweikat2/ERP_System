<?php

namespace App\Domains\Approvals\Actions;

use App\Domains\Approvals\Models\Approval;
use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Inventory\Actions\PreviewStockDocument;
use App\Domains\Inventory\Enums\StockDocumentType;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Models\User;
use App\Support\BusinessException;
use Illuminate\Support\Facades\DB;

class DecideInventoryApproval
{
    public function execute(int $id, string $decision, string $reason, int $actorId): Approval
    {
        return DB::transaction(function () use ($id, $decision, $reason, $actorId) {
            StoreSetting::sharedLock()->findOrFail(1);
            $actor = User::findOrFail($actorId);
            if (! $actor->hasPermission('approvals.decide') || ! $actor->hasPermission('inventory.view_cost')) {
                throw new BusinessException('APPROVAL_PERMISSION_REQUIRED', 'الموافقة على قيمة المخزون تحتاج صلاحية الموافقات وعرض التكلفة.', 403);
            }
            $identity = Approval::findOrFail($id);
            $type = StockDocumentType::from($identity->source_type);
            $class = $type->model();
            $doc = $class::with('lines')->lockForUpdate()->findOrFail($identity->source_id);
            $approval = Approval::lockForUpdate()->findOrFail($id);
            if ($approval->status === $decision && $doc->approval_id === $id) {
                return $approval;
            }
            if ($approval->status !== 'pending' || $doc->status !== 'pending' || $doc->version !== $approval->source_version || $doc->approval_id !== $id) {
                throw new BusinessException('APPROVAL_STALE', 'الموافقة لم تعد تخص النسخة الحالية من المستند.', 409);
            }
            if ($approval->segregate_requester && in_array($actorId, [$approval->requested_by, $doc->created_by, $doc->updated_by], true)) {
                throw new BusinessException('SELF_APPROVAL_FORBIDDEN', 'سياسة فصل المهام تتطلب موافقة مستخدم آخر.', 403);
            }
            $policy = DB::table('approval_policies')->where('key', $approval->policy_key)->sharedLock()->first();
            if ($decision === 'approved' && $policy->version !== $approval->policy_version) {
                throw new BusinessException('APPROVAL_POLICY_CHANGED', 'تغيرت سياسة الاعتماد. أعد تحرير المستند وإرساله للموافقة.', 409);
            }
            if ($decision === 'approved') {
                $preview = app(PreviewStockDocument::class)->execute($type, $doc);
                if (! hash_equals($approval->payload_hash, $preview['hash'])) {
                    throw new BusinessException('APPROVAL_PAYLOAD_CHANGED', 'تغيرت بيانات أو تكلفة المخزون. أعد تحرير المستند وإرساله للموافقة.', 409);
                }
            }
            $approval->update(['status' => $decision, 'decided_by' => $actorId, 'decision_reason' => $reason, 'decided_at' => now()]);
            DB::table('approval_steps')->insert(['approval_id' => $id, 'decision' => $decision, 'actor_id' => $actorId, 'reason' => $reason, 'occurred_at' => now()]);
            $doc->update(['status' => $decision]);
            app(RecordAudit::class)->execute('approvals.'.$decision, $type->value, $doc->id, null, ['approval_id' => $id, 'source_version' => $doc->version, 'reason' => $reason], $actorId);

            return $approval;
        }, 5);
    }
}
