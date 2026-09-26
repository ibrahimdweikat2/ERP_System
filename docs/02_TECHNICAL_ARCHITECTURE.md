# Technical Architecture

## 1. Architecture Style
Use a **modular monolith** in Laravel. The system is one deployable backend with strong domain boundaries. This provides transactional integrity and simpler operations than microservices while keeping code organized enough for future extraction if ever needed.

## 2. Backend Stack
- PHP + Laravel.
- REST JSON API.
- MySQL 8+.
- Laravel Queue: `database` connection.
- Laravel Scheduler for recurring jobs.
- Laravel Sanctum for first-party SPA authentication.
- Policies/Gates plus custom permission middleware for authorization.
- Form Requests for validation.
- API Resources/DTOs for response contracts.
- Service/action classes for domain operations.
- DB transactions around all financial and stock posting operations.

## 3. Frontend Stack
- React.
- TypeScript with strict mode.
- Tailwind CSS.
- React Router.
- Query/data-fetching layer such as TanStack Query.
- Form handling with typed validation.
- RTL-aware layout from the first component.
- Reusable data table, filters, money input, date input, status badge, approval dialog and print layouts.

## 4. Domain Modules
Suggested backend structure:
```text
app/
  Domains/
    Identity/
    StoreSetup/
    Catalog/
    Inventory/
    Purchasing/
    Sales/
    Customers/
    Installments/
    Checks/
    Payments/
    Accounting/
    Tax/
    CashBank/
    Expenses/
    Warranty/
    Reporting/
    Approvals/
    Audit/
    Notifications/
```

Each domain may contain:
```text
Actions/
DTOs/
Enums/
Events/
Exceptions/
Jobs/
Models/
Policies/
Queries/
Repositories/   (only when abstraction is justified)
Services/
Support/
```

Avoid a giant `Services` directory with unrelated classes.

## 5. Posting Architecture
Operational documents should have state:
`draft -> approved(optional) -> posted -> reversed/cancelled`.

Posting uses dedicated actions such as:
- `PostSalesInvoiceAction`
- `PostPurchaseInvoiceAction`
- `ReceiveCustomerPaymentAction`
- `ClearCheckAction`
- `BounceCheckAction`
- `PostInventoryAdjustmentAction`

A posting action must:
1. lock/check source document state;
2. validate accounting configuration;
3. validate stock/serial rules where relevant;
4. open a MySQL transaction;
5. create inventory movements if applicable;
6. create payment/check transitions if applicable;
7. create balanced journal entry;
8. mark source as posted;
9. write audit metadata;
10. commit;
11. dispatch non-critical after-commit jobs such as notifications/export refreshes.

## 6. Queue Without Redis
Use:
```env
QUEUE_CONNECTION=database
```
Laravel standard queue tables are stored in MySQL. Use multiple queue names logically even with the same driver:
- `default`
- `reports`
- `exports`
- `notifications`
- `maintenance`

Run separate workers where beneficial:
```bash
php artisan queue:work database --queue=default --tries=3
php artisan queue:work database --queue=reports,exports --tries=2 --timeout=300
php artisan queue:work database --queue=notifications --tries=5
```
Managed by Supervisor/systemd on Linux or an equivalent process manager on Windows Server.

## 7. Transaction Boundaries
Never queue core financial posting itself unless the UI explicitly represents a pending state. Normal sale, payment, check receipt and posting should complete transactionally in the request. Queue heavy side effects only after commit.

## 8. File/Document Storage
Store invoice PDFs, receipts, check scans, payment proofs and attachments with metadata in DB and binary files on storage disk. Do not store large binaries in MySQL.

## 9. Idempotency
Critical APIs should support idempotency where duplicate submissions are dangerous: payment receipt, check state changes, invoice posting, return posting. Store request keys and resulting document IDs.

## 10. Error Strategy
Use domain exceptions with user-safe Arabic/English messages and machine-readable codes. Never expose SQL errors or stack traces to production users.
