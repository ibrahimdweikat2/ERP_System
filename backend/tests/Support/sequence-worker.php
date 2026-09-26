<?php

use App\Domains\StoreSetup\Actions\NextDocumentNumber;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! str_ends_with(config('database.connections.mysql.database'), '_test')) {
    throw new RuntimeException('Test database required');
}
for ($i = 0; $i < 10; $i++) {
    echo app(NextDocumentNumber::class)->execute('journal_entry', '2026-09-06').PHP_EOL;
}
