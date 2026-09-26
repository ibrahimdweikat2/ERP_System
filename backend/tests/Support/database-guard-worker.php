<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

require __DIR__.'/../../vendor/autoload.php';

// The dangerous migration hook is replaced with a probe. This worker never
// connects to or modifies an incorrectly named database, even if the guard fails.
$test = new class('guard') extends TestCase
{
    use RefreshDatabase;

    public function refreshDatabase(): void
    {
        fwrite(STDERR, 'MIGRATION_HOOK_REACHED');
        exit(2);
    }

    public function checkGuard(): void
    {
        $this->setUp();
    }
};

try {
    $test->checkGuard();
    exit(3);
} catch (RuntimeException $exception) {
    if ($exception->getMessage() !== 'Tests require a dedicated database whose name ends in _test.') {
        fwrite(STDERR, $exception->getMessage());
        exit(4);
    }
    echo 'REJECTED_BEFORE_MIGRATIONS';
}
