<?php

use App\Domains\Purchasing\Actions\PostGoodsReceipt;
use App\Support\BusinessException;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! str_ends_with(config('database.connections.mysql.database'), '_test')) {
    throw new RuntimeException('Test database required.');
}
try {
    $doc = app(PostGoodsReceipt::class)->execute((int) $argv[1], 1, (int) $argv[2]);
    echo 'POSTED:'.$doc->document_no;
} catch (BusinessException $e) {
    echo 'REJECTED:'.$e->errorCode;
}
