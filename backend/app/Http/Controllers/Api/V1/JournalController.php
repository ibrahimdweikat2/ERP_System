<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Accounting\Actions\ChangePeriodState;
use App\Domains\Accounting\Actions\PostJournal;
use App\Domains\Accounting\Actions\ReverseJournal;
use App\Domains\Accounting\Actions\SaveManualJournal;
use App\Domains\Accounting\Models\JournalEntry;
use App\Domains\Audit\Actions\RecordAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveJournalRequest;
use App\Http\Resources\JournalEntryResource;
use App\Support\BusinessException;
use App\Support\IdempotentRequest;
use App\Support\PerPage;
use App\Support\Search;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class JournalController extends Controller
{
    public function index(Request $r): AnonymousResourceCollection
    {
        $d = $r->validate(['status' => ['nullable', Rule::in(['draft', 'posted'])], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'page' => ['nullable', 'integer', 'min:1']]);
        $q = JournalEntry::with(['journal', 'reversal']);
        foreach (['status' => '=', 'from' => '>=', 'to' => '<='] as $key => $op) {
            if (! empty($d[$key])) {
                $q->where($key === 'status' ? 'status' : 'entry_date', $op, $d[$key]);
            }
        }

        Search::apply($q, $r->query('search'), ['entry_no', 'description', 'reference_type']);

        return JournalEntryResource::collection($q->orderByDesc('id')->paginate(PerPage::resolve(25)));
    }

    public function show(JournalEntry $journalEntry): JournalEntryResource
    {
        return new JournalEntryResource($journalEntry->load(['lines.account', 'journal', 'reversal']));
    }

    public function store(SaveJournalRequest $r, SaveManualJournal $action, IdempotentRequest $idem): JsonResponse
    {
        $result = $idem->execute($r->user()->id, 'journal.create', (string) $r->header('Idempotency-Key'), $r->validated(), function () use ($r, $action) {
            return ['id' => $action->execute($r->validated(), $r->user()->id)->id];
        });

        return (new JournalEntryResource(JournalEntry::with(['lines.account', 'journal', 'reversal'])->findOrFail($result['id'])))->response()->setStatusCode(201);
    }

    public function update(SaveJournalRequest $r, JournalEntry $journalEntry, SaveManualJournal $action): JournalEntryResource
    {
        return new JournalEntryResource($action->execute($r->validated(), $r->user()->id, $journalEntry->id));
    }

    public function post(Request $r, JournalEntry $journalEntry, PostJournal $action, IdempotentRequest $idem): JournalEntryResource
    {
        if ($journalEntry->reference_type !== 'manual') {
            throw new BusinessException('POST_SOURCE_DOCUMENT', 'رحّل المستند عبر مساره التشغيلي.');
        }
        $result = $idem->execute($r->user()->id, 'journal.post.'.$journalEntry->id, (string) $r->header('Idempotency-Key'), ['id' => $journalEntry->id], fn () => ['id' => $action->execute($journalEntry->id, $r->user()->id)->id]);

        return new JournalEntryResource(JournalEntry::with(['lines.account', 'journal', 'reversal'])->findOrFail($result['id']));
    }

    public function reverse(Request $r, JournalEntry $journalEntry, ReverseJournal $action, IdempotentRequest $idem): JournalEntryResource
    {
        $data = $r->validate(['date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'min:5', 'max:800']]);
        $result = $idem->execute($r->user()->id, 'journal.reverse.'.$journalEntry->id, (string) $r->header('Idempotency-Key'), $data, fn () => ['id' => $action->execute($journalEntry->id, $data['date'], $data['reason'], $r->user()->id)->id]);

        return new JournalEntryResource(JournalEntry::with(['lines.account', 'journal', 'reversal'])->findOrFail($result['id']));
    }

    public function destroy(Request $r, JournalEntry $journalEntry, RecordAudit $audit): never
    {
        $audit->execute('accounting.deletion_rejected', 'journal_entry', $journalEntry->id, after: ['status' => $journalEntry->status]);
        throw new BusinessException('JOURNAL_DELETE_FORBIDDEN', 'لا يمكن حذف القيود. استخدم العكس للقيود المرحّلة.');
    }

    public function periodState(Request $r, int $period, ChangePeriodState $action): JsonResponse
    {
        $data = $r->validate(['status' => ['required', Rule::in(['open', 'soft_closed', 'locked'])], 'reason' => ['required', 'string', 'min:5', 'max:1000']]);

        return response()->json(['data' => $action->execute($period, $data['status'], $data['reason'], $r->user()->id)]);
    }
}
