<?php

use App\Domains\Accounting\Actions\PostJournal;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! str_ends_with(config('database.connections.mysql.database'), '_test')) {
    throw new RuntimeException('Test database required');
}
echo app(PostJournal::class)->execute((int) $argv[1], (int) $argv[2])->entry_no;
