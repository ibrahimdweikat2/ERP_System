<?php

namespace App\Domains\Approvals\Actions;

use App\Domains\Approvals\Models\Approval;
use App\Domains\Audit\Actions\RecordAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The store owner never waits on anyone: an approval they request is approved on the
 * spot, with the same step and audit trail a manual decision leaves.
 */
class OwnerAutoApproval
{
    public const REASON = 'اعتماد تلقائي — المالك';

    /** Returns true when the approval was granted automatically. */
    public function apply(?Approval $approval, int $actorId): bool
    {
        if (! $approval || ! User::find($actorId)?->isOwner()) {
            return false;
        }
        $approval->update(['status' => 'approved', 'decided_by' => $actorId, 'decision_reason' => self::REASON, 'decided_at' => now()]);
        DB::table('approval_steps')->insert(['approval_id' => $approval->id, 'decision' => 'approved', 'actor_id' => $actorId, 'reason' => self::REASON, 'occurred_at' => now()]);
        app(RecordAudit::class)->execute('approvals.approved', $approval->source_type, $approval->source_id, null, ['approval_id' => $approval->id, 'reason' => self::REASON], $actorId);

        return true;
    }
}
