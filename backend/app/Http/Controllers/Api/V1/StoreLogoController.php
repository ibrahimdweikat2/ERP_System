<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\StoreSetup\Actions\SaveStoreLogo;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreLogoController extends Controller
{
    public function store(Request $r, SaveStoreLogo $action): JsonResponse
    {
        $r->validate(['file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048']]);
        $store = $action->execute($r->file('file'), $r->user()->id);

        return response()->json(['data' => ['logo_url' => $store->logoUrl()]], 201);
    }

    public function destroy(Request $r, SaveStoreLogo $action): JsonResponse
    {
        $action->remove($r->user()->id);

        return response()->json(['message' => 'تم حذف الشعار.']);
    }
}
