<?php

return [
    'default' => env('CACHE_STORE', 'database'),
    'stores' => [
        'array' => ['driver' => 'array', 'serialize' => false],
        'database' => ['driver' => 'database', 'connection' => 'mysql', 'table' => 'cache',
            'lock_connection' => 'mysql', 'lock_table' => 'cache_locks'],
        'file' => ['driver' => 'file', 'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data')],
    ],
    'prefix' => 'erp_cache_',
];
