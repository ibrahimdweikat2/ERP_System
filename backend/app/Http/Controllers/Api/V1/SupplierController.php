<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Purchasing\Actions\DeleteSupplier;
use App\Domains\Purchasing\Actions\SaveSupplier;
use App\Domains\Purchasing\Models\Supplier;
use App\Domains\StoreSetup\Models\Currency;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveSupplierRequest;
use App\Http\Resources\SupplierResource;
use App\Support\IdempotentRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SupplierController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $d = $request->validate(['search' => ['nullable', 'string', 'max:180'], 'active' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'between:1,100']]);
        $q = Supplier::when($d['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('code', 'like', "%$s%")->orWhere('legal_name', 'like', "%$s%")->orWhere('trade_name', 'like', "%$s%")->orWhere('tax_number', 'like', "%$s%")));
        if (isset($d['active'])) {
            $q->where('active', $d['active']);
        }

        return SupplierResource::collection($q->orderBy('legal_name')->orderBy('id')->paginate(\App\Support\PerPage::resolve(25)));
    }

    public function show(Supplier $supplier): SupplierResource
    {
        return new SupplierResource($supplier);
    }

    public function store(SaveSupplierRequest $request, SaveSupplier $save, IdempotentRequest $idempotency): JsonResponse
    {
        $result = $idempotency->execute($request->user()->id, 'supplier.create', (string) $request->header('Idempotency-Key'), $request->validated(), fn () => ['id' => $save->execute($request->validated(), $request->user()->id)->id]);

        return (new SupplierResource(Supplier::findOrFail($result['id'])))->response()->setStatusCode(201);
    }

    public function update(SaveSupplierRequest $request, Supplier $supplier, SaveSupplier $save): SupplierResource
    {
        return new SupplierResource($save->execute($request->validated(), $request->user()->id, $supplier->id));
    }

    public function destroy(Request $request, Supplier $supplier, DeleteSupplier $action): JsonResponse
    {
        $action->execute($supplier->id, $request->user()->id);

        return response()->json(['message' => 'تم حذف المورد.']);
    }

    public function currencies(): JsonResponse
    {
        return response()->json(['data' => Currency::where('is_active', true)->orderBy('code')->get(['code', 'name'])]);
    }
}
