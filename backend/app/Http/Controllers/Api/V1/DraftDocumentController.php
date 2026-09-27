<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Documents\Actions\DeleteDraftDocument;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** DELETE on any document route: removes it while it is still a draft (see DeleteDraftDocument). */
class DraftDocumentController extends Controller
{
    public function destroy(Request $r, DeleteDraftDocument $action): JsonResponse
    {
        $id = (int) collect($r->route()->parameters())->except(['draftKind', 'settlementKind', 'treasuryKind', 'stockKind'])->first();
        $action->execute($r->route('draftKind'), $id, $r->user()->id);

        return response()->json(['message' => 'تم حذف المسودة.']);
    }
}
