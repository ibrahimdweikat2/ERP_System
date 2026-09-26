<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Catalog\Actions\SaveProductImage;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductImage;
use App\Http\Controllers\Controller;
use App\Support\BusinessException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductImageController extends Controller
{
    public function store(Request $r, Product $product, SaveProductImage $action): JsonResponse
    {
        $r->validate(['file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);

        $image = $action->execute($product->id, $r->file('file'), $r->user()->id);

        return response()->json(['data' => [...$image->toArray(), 'image_url' => $image->url()]], 201);
    }

    public function destroy(Request $r, Product $product, SaveProductImage $action): JsonResponse
    {
        if (! ProductImage::where('product_id', $product->id)->exists()) {
            throw new BusinessException('PRODUCT_IMAGE_MISSING', 'لا توجد صورة لهذا المنتج.', 404);
        }
        $action->remove($product->id, $r->user()->id);

        return response()->json(['message' => 'تم حذف صورة المنتج.']);
    }
}
