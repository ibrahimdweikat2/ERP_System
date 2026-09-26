<?php

namespace Tests\Feature;

use App\Domains\Notifications\Jobs\QueueProbe;
use App\Domains\StoreSetup\Actions\NextDocumentNumber;
use App\Domains\StoreSetup\Models\DocumentSequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FoundationSequenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_numbering_handles_backdated_year_without_resetting_other_counters(): void
    {
        $action = app(NextDocumentNumber::class);
        $this->assertSame('JE/2026/000001', $action->execute('journal_entry', '2026-09-06'));
        $this->assertSame('JE/2025/000001', $action->execute('journal_entry', '2025-12-31'));
        $this->assertSame('JE/2026/000002', $action->execute('journal_entry', '2026-09-07'));
    }

    public function test_rollback_does_not_consume_a_number(): void
    {
        DB::beginTransaction();
        app(NextDocumentNumber::class)->execute('journal_entry', '2026-09-06');
        DB::rollBack();
        $this->assertSame('JE/2026/000001', app(NextDocumentNumber::class)->execute('journal_entry', '2026-09-06'));
    }

    public function test_never_reset_sequence_keeps_incrementing_across_years(): void
    {
        DocumentSequence::where('document_type', 'journal_entry')->update(['reset_policy' => 'never']);
        $this->assertSame('JE/000001', app(NextDocumentNumber::class)->execute('journal_entry', '2026-09-06'));
        $this->assertSame('JE/000002', app(NextDocumentNumber::class)->execute('journal_entry', '2027-09-06'));
    }

    public function test_queue_retry_is_idempotent(): void
    {
        $id = (string) Str::uuid();
        DB::table('queue_probe_runs')->insert(['id' => $id, 'queue' => 'maintenance', 'dispatched_at' => now()]);
        $job = new QueueProbe($id);
        $job->handle();
        $job->handle();
        $this->assertDatabaseHas('queue_probe_runs', ['id' => $id, 'completion_count' => 1]);
    }
}
