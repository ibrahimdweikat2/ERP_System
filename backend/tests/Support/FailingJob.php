<?php

namespace Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;

class FailingJob implements ShouldQueue
{
    public int $tries = 1;

    public function handle(): void
    {
        throw new \RuntimeException('Intentional foundation failure-path test');
    }
}
