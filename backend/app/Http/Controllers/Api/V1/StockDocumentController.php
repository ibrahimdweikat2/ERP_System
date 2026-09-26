<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Inventory\Actions\PostStockDocument;
use App\Domains\Inventory\Actions\SaveStockDocument;
use App\Domains\Inventory\Actions\SubmitStockDocument;
use App\Domains\Inventory\Enums\StockDocumentType;
use App\Domains\Inventory\Models\InventoryDocument;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveStockDocumentRequest;
use App\Http\Resources\StockDocumentResource;
use App\Support\BusinessException;
use App\Support\IdempotentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class StockDocumentController extends Controller
{
    private function type(Request $r): StockDocumentType
    {
        return StockDocumentType::fromRoute($r->route('stockKind'));
    }

    private function document(Request $r): InventoryDocument
    {
        $class = $this->type($r)->model();

        return $class::with(['lines.product', 'location', 'approval'])->findOrFail($r->route('id'));
    }

    private function allowWrite(Request $r, InventoryDocument $doc): void
    {
        $type = $this->type($r);
        $permission = $type === StockDocumentType::Adjustment && $doc->adjustment_kind === 'opening' ? 'inventory.receive' : $type->permission();
        abort_unless($r->user()->hasPermission($permission), 403);
    }

    public function index(Request $r): AnonymousResourceCollection
    {
        $d = $r->validate(['status' => ['nullable', Rule::in(['draft', 'pending', 'approved', 'rejected', 'posted'])], 'search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $class = $this->type($r)->model();
        $q = $class::with(['location', 'approval'])->when($d['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->when($d['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('document_no', 'like', "%$s%")->orWhere('reason', 'like', "%$s%")));

        return StockDocumentResource::collection($q->orderByDesc('id')->paginate(\App\Support\PerPage::resolve(25)));
    }

    public function show(Request $r): StockDocumentResource
    {
        return new StockDocumentResource($this->document($r));
    }

    public function store(SaveStockDocumentRequest $r, SaveStockDocument $action, IdempotentRequest $idem): JsonResponse
    {
        $type = $this->type($r);
        $result = $idem->execute($r->user()->id, $type->value.'.create', (string) $r->header('Idempotency-Key'), $r->validated(), fn () => ['id' => $action->execute($type, $r->validated(), $r->user()->id)->id]);
        $class = $type->model();

        return (new StockDocumentResource($class::with(['lines.product', 'location', 'approval'])->findOrFail($result['id'])))->response()->setStatusCode(201);
    }

    public function update(SaveStockDocumentRequest $r, SaveStockDocument $action): StockDocumentResource
    {
        $doc = $this->document($r);
        $this->allowWrite($r, $doc);
        if ($doc->status === 'posted') {
            app(RecordAudit::class)->execute('inventory.edit_rejected', $this->type($r)->value, $doc->id);
            throw new BusinessException('STOCK_DOCUMENT_IMMUTABLE', 'المستند المرحّل لا يُعدّل. أنشئ مستند تصحيح جديداً.');
        }

        return new StockDocumentResource($action->execute($this->type($r), $r->validated(), $r->user()->id, $doc->id));
    }

    public function submit(Request $r, SubmitStockDocument $action, IdempotentRequest $idem): StockDocumentResource
    {
        return $this->transition($r, $idem, fn ($type, $doc, $v) => $action->execute($type, $doc->id, $v, $r->user()->id), 'submit');
    }

    public function post(Request $r, PostStockDocument $action, IdempotentRequest $idem): StockDocumentResource
    {
        return $this->transition($r, $idem, fn ($type, $doc, $v) => $action->execute($type, $doc->id, $v, $r->user()->id), 'post');
    }

    private function transition(Request $r, IdempotentRequest $idem, \Closure $action, string $event): StockDocumentResource
    {
        $doc = $this->document($r);
        $this->allowWrite($r, $doc);
        $type = $this->type($r);
        $d = $r->validate(['version' => ['required', 'integer', 'min:1']]);
        $result = $idem->execute($r->user()->id, $type->value.'.'.$event.'.'.$doc->id, (string) $r->header('Idempotency-Key'), $d, fn () => ['id' => $action($type, $doc, (int) $d['version'])->id]);
        $class = $type->model();

        return new StockDocumentResource($class::with(['lines.product', 'location', 'approval'])->findOrFail($result['id']));
    }

    public function destroy(Request $r): never
    {
        $doc = $this->document($r);
        $this->allowWrite($r,$doc);
        app(RecordAudit::class)->execute('inventory.deletion_rejected',$this->type($r)->value,$doc->id);
        throw new BusinessException('STOCK_DOCUMENT_DELETE_FORBIDDEN','مستندات المخزون محفوظة للتدقيق ولا تُحذف.');
    }
}
