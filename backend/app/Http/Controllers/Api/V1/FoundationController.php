<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\StoreSetup\Models\DocumentSequence;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Http\Controllers\Controller;
use App\Support\BusinessException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FoundationController extends Controller
{
    public function storeContext(): JsonResponse
    {
        return response()->json(['data' => StoreSetting::findOrFail(1)->only(['trade_name', 'platform_name', 'logo_url', 'legal_name', 'address', 'phone', 'tax_number', 'vat_registered', 'invoice_footer', 'base_currency', 'timezone', 'locale'])]);
    }

    // Public (pre-login): only what the sign-in page displays, never other store details.
    public function branding(): JsonResponse
    {
        $store = StoreSetting::find(1);

        return response()->json(['data' => ['platform_name' => $store?->platform_name, 'logo_url' => $store?->logoUrl()]]);
    }

    public function sequences(): JsonResponse
    {
        return response()->json(['data' => DocumentSequence::orderBy('document_type')->get()]);
    }

    public function saveSequence(Request $r, DocumentSequence $sequence): JsonResponse
    {
        $data = $r->validate(['prefix' => ['required', 'regex:/^[A-Za-z0-9_-]{1,19}\\/?$/'], 'padding' => ['required', 'integer', 'between:3,12'], 'next_number' => ['required', 'integer', 'min:1', 'max:999999999999'], 'reset_policy' => ['required', Rule::in(['yearly', 'never'])]]);

        return DB::transaction(function () use ($data, $sequence) {
            $sequence = DocumentSequence::lockForUpdate()->findOrFail($sequence->id);
            if (DB::table('document_sequence_counters')->where('document_sequence_id', $sequence->id)->exists()) {
                throw new BusinessException('SEQUENCE_ALREADY_USED', 'لا يمكن تعديل تنسيق تسلسل استُخدم بالفعل.');
            }
            $before = $sequence->toArray();
            $sequence->update($data);
            app(RecordAudit::class)->execute('sequences.updated', 'document_sequence', $sequence->id, $before, $sequence->toArray());

            return response()->json(['data' => $sequence]);
        }, 3);
    }

    public function health(): JsonResponse
    {
        $version = DB::selectOne('SELECT VERSION() AS version')->version;

        return response()->json(['data' => ['database' => ['engine' => 'MySQL', 'version' => $version, 'connected' => true],
            'queue' => ['connection' => config('queue.default'), 'pending' => DB::table('jobs')->count(), 'failed' => DB::table('failed_jobs')->count(),
                'last_probe' => DB::table('queue_probe_runs')->whereNotNull('completed_at')->orderByDesc('completed_at')->first()],
            'timezone' => config('app.timezone'), 'phase' => 0]]);
    }
}
