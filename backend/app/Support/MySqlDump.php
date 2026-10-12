<?php

namespace App\Support;

use Symfony\Component\Process\Process;

/**
 * Runs mysqldump against the application database. Credentials go through a
 * private temporary defaults file, never the command line.
 */
class MySqlDump
{
    /**
     * @param  list<string>  $options  mysqldump options, e.g. --single-transaction
     * @param  list<string>  $tables  empty for the whole database
     */
    public static function run(array $options, string $resultFile, array $tables = []): void
    {
        $db = config('database.connections.mysql');
        $credentials = tempnam(sys_get_temp_dir(), 'erp-backup-');
        try {
            chmod($credentials, 0600);
            $quote = fn ($v) => '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], (string) $v).'"';
            file_put_contents($credentials, "[client]\nuser=".$quote($db['username'])."\npassword=".$quote($db['password'])."\nhost=".$quote($db['host'])."\nport=".(int) $db['port']."\n");
            $process = new Process([config('erp.mysqldump_binary'), '--defaults-extra-file='.$credentials, ...$options, '--result-file='.$resultFile, $db['database'], ...$tables]);
            $process->setTimeout(1800);
            $process->mustRun();
        } finally {
            if (is_file($credentials)) {
                unlink($credentials);
            }
        }
    }
}
