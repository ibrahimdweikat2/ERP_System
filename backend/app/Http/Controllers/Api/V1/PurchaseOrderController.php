<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Catalog\Models\Product;
use App\Domains\Purchasing\Actions\PurchaseOrderWorkflow;
use App\Domains\Purchasing\Actions\SavePurchaseOrder;
use App\Domains\Purchasing\Models\PurchaseOrder;
use App\Domains\Tax\Models\TaxCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\SavePurchaseOrderRequest;
use App\Http\Resources\PurchaseOrderResource;
use App\Support\BusinessException;
use App\Support\Decimal;
use App\Support\IdempotentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PurchaseOrderController extends Controller
{
    public function index(Request $r): AnonymousResourceCollection
    {
        $d = $r->validate(['status' => ['nullable', Rule::in(['draft', 'pending', 'approved', 'rejected', 'issued'])], 'search' => ['nullable', 'string', 'max:100'], 'supplier_id' => ['nullable', 'integer'], 'per_page' => ['nullable', 'integer', 'between:1,100']]);
        $q = PurchaseOrder::when($d['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->when($d['supplier_id'] ?? null, fn ($q, $s) => $q->where('supplier_id', $s))->when($d['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('document_no', 'like', "%$s%")->orWhere('supplier_reference', 'like', "%$s%")->orWhere('supplier_snapshot->legal_name', 'like', "%$s%")));

        return PurchaseOrderResource::collection($q->orderByDesc('id')->paginate(\App\Support\PerPage::resolve(25)));
    }

    public function show(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        return new PurchaseOrderResource($purchaseOrder->load(['lines', 'approval']));
    }

    public function store(SavePurchaseOrderRequest $r, SavePurchaseOrder $save, IdempotentRequest $idem): JsonResponse
    {
        $result = $idem->execute($r->user()->id, 'purchase_order.create', (string) $r->header('Idempotency-Key'), $r->validated(), fn () => ['id' => $save->execute($r->validated(), $r->user()->id)->id]);

        return (new PurchaseOrderResource(PurchaseOrder::with(['lines', 'approval'])->findOrFail($result['id'])))->response()->setStatusCode(201);
    }

    public function update(SavePurchaseOrderRequest $r, PurchaseOrder $purchaseOrder, SavePurchaseOrder $save): PurchaseOrderResource
    {
        if ($purchaseOrder->status === 'issued') {
            app(RecordAudit::class)->execute('purchasing.order_edit_rejected', 'purchase_order', $purchaseOrder->id);
            throw new BusinessException('PURCHASE_ORDER_NOT_EDITABLE', 'أمر الشراء الصادر محفوظ ولا يمكن تعديله.', 409);
        }

        return new PurchaseOrderResource($save->execute($r->validated(), $r->user()->id, $purchaseOrder->id));
    }

    public function transition(Request $r, PurchaseOrder $purchaseOrder, PurchaseOrderWorkflow $workflow, IdempotentRequest $idem): PurchaseOrderResource
    {
        $event = $r->route('event');
        $d = $r->validate(['version' => ['required', 'integer', 'min:1'], 'decision' => [$event === 'decide' ? 'required' : 'prohibited', Rule::in(['approved', 'rejected'])], 'reason' => [$event === 'decide' ? 'required' : 'prohibited', 'string', 'min:5', 'max:1000']]);
        $result = $idem->execute($r->user()->id, 'purchase_order.'.$event.'.'.$purchaseOrder->id, (string) $r->header('Idempotency-Key'), $d, fn () => ['id' => match ($event) {
            'submit' => $workflow->submit($purchaseOrder->id, (int) $d['version'], $r->user()->id)->id,'issue' => $workflow->issue($purchaseOrder->id, (int) $d['version'], $r->user()->id)->id,'decide' => $workflow->decide($purchaseOrder->id, (int) $d['version'], $d['decision'], $d['reason'], $r->user()->id)->id
        }]);

        return new PurchaseOrderResource(PurchaseOrder::with(['lines', 'approval'])->findOrFail($result['id']));
    }

    public function products(Request $r): JsonResponse
    {
        $d = $r->validate(['per_page' => ['nullable', 'integer', 'between:1,100']]);

        return response()->json(Product::with('unit:id,code,name_ar,decimal_places')->where('active', true)->select(['id', 'sku', 'name_ar', 'unit_id', 'serial_tracked', 'tax_code_id'])->orderBy('sku')->paginate(\App\Support\PerPage::resolve(100)));
    }

    public function taxes(): JsonResponse
    {
        return response()->json(['data' => TaxCode::orderBy('code')->orderByDesc('effective_from')->get(['id', 'code', 'name_ar', 'category', 'rate', 'effective_from', 'effective_to'])]);
    }

    public function policy(): JsonResponse
    {
        return response()->json(['data' => DB::table('approval_policies')->where('key', 'purchase_order')->first()]);
    }

    public function savePolicy(Request $r): JsonResponse
    {
        $d = $r->validate(['threshold' => ['required', 'string', Decimal::MONEY_RULE], 'segregate_requester' => ['required', 'boolean'], 'reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return DB::transaction(function () use ($d, $r) {
            $old = DB::table('approval_policies')->where('key', 'purchase_order')->lockForUpdate()->first();
            DB::table('approval_policies')->where('key', 'purchase_order')->update(['threshold' => $d['threshold'], 'segregate_requester' => $d['segregate_requester'], 'version' => $old->version + 1, 'updated_at' => now()]);
            app(RecordAudit::class)->execute('purchasing.order_policy_changed', 'approval_policy', null, (array) $old, $d, $r->user()->id);

            return $this->policy();
        }, 3);
    }
}
