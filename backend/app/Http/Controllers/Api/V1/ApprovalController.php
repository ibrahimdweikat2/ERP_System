<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Approvals\Actions\DecideInventoryApproval;
use App\Domains\Approvals\Models\Approval;
use App\Domains\Purchasing\Actions\PurchaseOrderWorkflow;
use App\Http\Controllers\Controller;
use App\Support\IdempotentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApprovalController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'superseded'])]]);
        $p = Approval::when(! $r->user()->hasPermission('purchasing.view'), fn ($q) => $q->where('source_type', '!=', 'purchase_order'))->when($d['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->orderByDesc('id')->paginate(\App\Support\PerPage::resolve(25));
        $p->through(function ($a) use ($r) {
            $d = $a->toArray();
            unset($d['payload_hash'], $d['payload_json']);
            if ($a->source_type !== 'purchase_order' && ! $r->user()->hasPermission('inventory.view_cost')) {
                unset($d['amount'],$d['payload_json']);
            }

            return $d;
        });

        return response()->json($p);
    }

    public function decide(Request $r, Approval $approval, DecideInventoryApproval $action, IdempotentRequest $idem): JsonResponse
    {
        $d = $r->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $result = $idem->execute($r->user()->id, 'approval.decide.'.$approval->id, (string) $r->header('Idempotency-Key'), $d, function () use ($approval, $action, $d, $r) {
            if ($approval->source_type === 'purchase_order') {
                app(PurchaseOrderWorkflow::class)->decide($approval->source_id, $approval->source_version, $d['decision'], $d['reason'], $r->user()->id);

                return ['id' => $approval->id];
            }

            return ['id' => $action->execute($approval->id, $d['decision'], $d['reason'], $r->user()->id)->id];
        });

        return response()->json(['data' => Approval::findOrFail($result['id'])->only(['id', 'status', 'decision_reason', 'decided_by', 'decided_at'])]);
    }
}
