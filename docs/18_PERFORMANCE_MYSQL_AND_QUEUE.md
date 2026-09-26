# Performance, MySQL and Laravel Queue

## 1. Performance Goals
Interactive lists should normally return quickly with pagination/filtering. Heavy accounting/inventory reports must avoid loading millions of raw rows into PHP memory.

## 2. MySQL Strategy
- correct composite indexes based on actual filters/order;
- use `EXPLAIN ANALYZE` for slow queries;
- eager-load intentionally, avoid N+1;
- select only required columns;
- cursor/chunk processing for exports;
- database transactions kept short;
- avoid long user interaction inside transactions;
- use lock rows only where concurrency correctness requires.

## 3. Summary Tables
Permitted for performance where source remains immutable/auditable, e.g.:
- `inventory_balances` derived from movement ledger;
- daily sales aggregates;
- customer exposure summaries;
- report snapshots after period close.

Never replace the ledger with summaries.

## 4. Caching Without Redis
Use Laravel file/database cache initially for low-risk configuration/reference data if needed. Do not rely on cache as a correctness requirement. Examples:
- categories/brands;
- permission metadata;
- static configuration.

Avoid caching rapidly changing financial balances unless there is a clear invalidation design.

## 5. Database Queue
### Configuration
`QUEUE_CONNECTION=database`.

### Worker Segmentation
- default: short application jobs;
- reports,exports: long-running jobs;
- notifications: reminders/messages;
- maintenance: cleanup/rebuild jobs.

### Job Design
- jobs are idempotent;
- payload contains IDs, not giant serialized model graphs;
- refetch current data within job;
- define `tries`, `backoff`, `timeout` appropriately;
- record report/export progress in application table;
- use `afterCommit()` when job depends on committed records.

## 6. Database Queue Scaling Limits
MySQL-backed queue is suitable for this single-store ERP. If job volume becomes very high in a future deployment, queue infrastructure can be reconsidered, but **Redis is not part of this project**. Optimize first by separate workers, queue names, indexes and avoiding excessive tiny jobs.

## 7. Scheduler
One scheduler trigger:
```bash
* * * * * php /path/to/artisan schedule:run >> /dev/null 2>&1
```
Scheduled tasks decide due installments/checks, reminders, backup verification hooks, cleanup and aggregate refresh.

## 8. Key Performance Anti-Patterns to Reject
- recalculating entire stock history per product page;
- counting every installment row repeatedly for dashboard widgets;
- exporting via normal HTTP request with all rows in memory;
- unindexed polymorphic audit searches;
- joining huge ledgers without date/account filters;
- using JSON columns for fields that require frequent relational filtering.
