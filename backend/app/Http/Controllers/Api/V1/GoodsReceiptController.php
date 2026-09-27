<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Inventory\Models\StockLocation;
use App\Domains\Purchasing\Actions\PostGoodsReceipt;
use App\Domains\Purchasing\Actions\SaveGoodsReceipt;
use App\Domains\Purchasing\Models\GoodsReceipt;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveGoodsReceiptRequest;
use App\Http\Resources\GoodsReceiptResource;
use App\Support\BusinessException;
use App\Support\IdempotentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class GoodsReceiptController extends Controller
{
    public function index(Request $r): AnonymousResourceCollection
    {
        $d = $r->validate(['search' => ['nullable', 'string', 'max:120'], 'status' => ['nullable', Rule::in(['draft', 'posted'])], 'supplier_id' => ['nullable', 'integer'], 'purchase_order_id' => ['nullable', 'integer'], 'per_page' => ['nullable', 'integer', 'between:1,100']]);
        $q = GoodsReceipt::with('purchaseOrder:id,document_no')->when($d['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->when($d['supplier_id'] ?? null, fn ($q, $s) => $q->where('supplier_id', $s))->when($d['purchase_order_id'] ?? null, fn ($q, $s) => $q->where('purchase_order_id', $s))->when($d['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('document_no', 'like', "%$s%")->orWhere('delivery_reference', 'like', "%$s%")->orWhere('supplier_snapshot->legal_name', 'like', "%$s%")));

        return GoodsReceiptResource::collection($q->orderByDesc('id')->paginate(\App\Support\PerPage::resolve(25)));
    }

    public function show(GoodsReceipt $goodsReceipt): GoodsReceiptResource
    {
        return new GoodsReceiptResource($goodsReceipt->load(['lines.location', 'purchaseOrder:id,document_no']));
    }

    public function store(SaveGoodsReceiptRequest $r, SaveGoodsReceipt $save, IdempotentRequest $idem): JsonResponse
    {
        $result = $idem->execute($r->user()->id, 'goods_receipt.create', (string) $r->header('Idempotency-Key'), $r->validated(), fn () => ['id' => $save->execute($r->validated(), $r->user()->id)->id]);

        return (new GoodsReceiptResource(GoodsReceipt::with(['lines.location', 'purchaseOrder:id,document_no'])->findOrFail($result['id'])))->response()->setStatusCode(201);
    }

    public function update(SaveGoodsReceiptRequest $r, GoodsReceipt $goodsReceipt, SaveGoodsReceipt $save): GoodsReceiptResource
    {
        if ($goodsReceipt->status === 'posted') {
            app(RecordAudit::class)->execute('purchasing.receipt_edit_rejected', 'goods_receipt', $goodsReceipt->id);
            throw new BusinessException('RECEIPT_NOT_EDITABLE', 'سند الاستلام المرحّل محفوظ ولا يمكن تعديله.', 409);
        }

        return new GoodsReceiptResource($save->execute($r->validated(), $r->user()->id, $goodsReceipt->id));
    }

    public function post(Request $r, GoodsReceipt $goodsReceipt, PostGoodsReceipt $post, IdempotentRequest $idem): GoodsReceiptResource
    {
        abort_unless($goodsReceipt->purchase_order_id || $r->user()->hasPermission('purchasing.receive_without_po'), 403);
        $d = $r->validate(['version' => ['required', 'integer', 'min:1']]);
        $result = $idem->execute($r->user()->id, 'goods_receipt.post.'.$goodsReceipt->id, (string) $r->header('Idempotency-Key'), $d, fn () => ['id' => $post->execute($goodsReceipt->id, (int) $d['version'], $r->user()->id)->id]);

        return new GoodsReceiptResource(GoodsReceipt::with(['lines.location', 'purchaseOrder:id,document_no'])->findOrFail($result['id']));
    }

    public function locations(): JsonResponse
    {
        return response()->json(['data' => StockLocation::where('active', true)->orderBy('code')->get()]);
    }
}
