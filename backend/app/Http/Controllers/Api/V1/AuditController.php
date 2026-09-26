<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Audit\Models\AuditLog;
use App\Http\Controllers\Controller;
use App\Support\PerPage;
use App\Support\Search;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['entity_type' => ['nullable', 'string', 'max:100'], 'entity_id' => ['nullable', 'string', 'max:100'], 'action' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $q = AuditLog::with('actor:id,name');
        foreach (['entity_type', 'entity_id', 'action'] as $key) {
            if (! empty($d[$key])) {
                $q->where($key, $d[$key]);
            }
        }
        $q->when($d['from'] ?? null, fn ($q, $v) => $q->where('occurred_at', '>=', $v.' 00:00:00'))
            ->when($d['to'] ?? null, fn ($q, $v) => $q->where('occurred_at', '<', CarbonImmutable::parse($v)->addDay()));

        Search::apply($q, $r->query('search'), ['action', 'entity_type', 'entity_id']);

        return response()->json($q->orderByDesc('id')->paginate(PerPage::resolve(25)));
    }
}
