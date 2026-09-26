<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Catalog\Actions\DeleteProduct;
use App\Domains\Catalog\Actions\SaveProduct;
use App\Domains\Catalog\Actions\SuggestSku;
use App\Domains\Catalog\Models\Product;
use App\Domains\Tax\Models\TaxCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveProductRequest;
use App\Http\Resources\ProductResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductController extends Controller
{
    public function taxOptions(): JsonResponse
    {
        return response()->json(TaxCode::select('id', 'code', 'name_ar', 'category', 'rate', 'effective_from', 'effective_to')->orderBy('code')->paginate(\App\Support\PerPage::resolve(100)));
    }

    public function index(Request $r): AnonymousResourceCollection
    {
        $d = $r->validate(['search' => ['nullable', 'string', 'max:100'], 'category_id' => ['nullable', 'integer', 'exists:categories,id'], 'brand_id' => ['nullable', 'integer', 'exists:brands,id'], 'active' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'between:1,100']]);
        $q = Product::with(['image', 'barcodes', 'brand', 'category', 'unit', 'warrantyPolicy', 'taxCode']);
        if (! empty($d['search'])) {
            $s = $d['search'];
            $q->where(fn ($q) => $q->where('sku', 'like', "%$s%")->orWhere('name_ar', 'like', "%$s%")->orWhere('name_en', 'like', "%$s%")->orWhere('manufacturer_model', 'like', "%$s%")->orWhereHas('barcodes', fn ($q) => $q->where('barcode', mb_strtoupper(trim($s))))->orWhereHas('brand', fn ($q) => $q->where('name_ar', 'like', "%$s%")));
        }
        foreach (['category_id', 'brand_id', 'active'] as $key) {
            if (isset($d[$key])) {
                $q->where($key, $d[$key]);
            }
        }

        return ProductResource::collection($q->orderByDesc('id')->paginate(\App\Support\PerPage::resolve(25)));
    }

    public function skuSuggestion(Request $r, SuggestSku $suggest): JsonResponse
    {
        $d = $r->validate(['brand_id' => ['nullable', 'integer', 'exists:brands,id'], 'category_id' => ['nullable', 'integer', 'exists:categories,id']]);

        return response()->json(['data' => ['sku' => $suggest->execute($d['brand_id'] ?? null, $d['category_id'] ?? null)]]);
    }

    public function show(Product $product): ProductResource
    {
        return new ProductResource($product->load(['image', 'barcodes', 'brand', 'category', 'unit', 'warrantyPolicy', 'taxCode']));
    }

    public function store(SaveProductRequest $r, SaveProduct $action): JsonResponse
    {
        return (new ProductResource($action->execute($r->validated(), $r->user()->id)))->response()->setStatusCode(201);
    }

    public function update(SaveProductRequest $r, Product $product, SaveProduct $action): ProductResource
    {
        return new ProductResource($action->execute($r->validated(), $r->user()->id, $product->id));
    }

    public function destroy(Request $r, Product $product, DeleteProduct $action): JsonResponse
    {
        $action->execute($product->id, $r->user()->id);

        return response()->json(['message' => 'تم حذف المنتج.']);
    }
}
