<?php

return [
    'default' => env('QUEUE_CONNECTION', 'database'),
    'connections' => [
        'database' => [
            'driver' => 'database', 'connection' => 'mysql', 'table' => 'jobs',
            'queue' => 'default', 'retry_after' => 1900, 'after_commit' => true,
        ],
        'sync' => ['driver' => 'sync'],
    ],
    'batching' => ['database' => 'mysql', 'table' => 'job_batches'],
    'failed' => ['driver' => 'database-uuids', 'database' => 'mysql', 'table' => 'failed_jobs'],
];
