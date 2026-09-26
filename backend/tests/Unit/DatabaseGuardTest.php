<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class DatabaseGuardTest extends TestCase
{
    public function test_invalid_database_name_is_rejected_before_migration_traits_run(): void
    {
        $root = dirname(__DIR__, 2);
        $process = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), $root.'/tests/Support/database-guard-worker.php'], $root, ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'erp_invalid_guard_probe']);
        $process->run();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame('REJECTED_BEFORE_MIGRATIONS', trim($process->getOutput()));
        $this->assertStringNotContainsString('MIGRATION_HOOK_REACHED', $process->getErrorOutput());
    }
}
