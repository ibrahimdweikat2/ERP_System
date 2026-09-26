<?php

namespace App\Domains\Notifications\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

class QueueProbe implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public int $backoff = 10;

    public function __construct(public readonly string $runId) {}

    public function handle(): void
    {
        DB::transaction(function () {
            $run = DB::table('queue_probe_runs')->where('id', $this->runId)->lockForUpdate()->first();
            if (! $run || $run->completed_at) {
                return;
            }
            DB::table('queue_probe_runs')->where('id', $this->runId)->update(['completed_at' => now(), 'completion_count' => 1]);
        }, 3);
    }
}
