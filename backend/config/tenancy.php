<?php

// Every table not listed here is a company table: the query grammar adds
// "company_id = <current company>" to each read and write, and refuses to run
// without a company context. New tables are therefore company-scoped by default.
return [
    // Shared by the whole platform; never filtered.
    'global' => [
        'migrations', 'companies', 'permissions',
        'sessions', 'password_reset_tokens', 'cache', 'cache_locks',
        'jobs', 'job_batches', 'failed_jobs',
        'queue_probe_runs', 'backup_runs',
    ],
    // Rows belong to a company, or to the platform when company_id is NULL
    // (superadmin accounts and their audit trail).
    'nullable' => ['users', 'audit_logs'],
];
