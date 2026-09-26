# REST API Design

## 1. Conventions
Base: `/api/v1`

Use JSON with consistent envelope/error shape. Pagination mandatory on list endpoints. Filters use explicit query params. Money values should be serialized as strings/decimal-safe representations where necessary to avoid JavaScript floating precision problems.

## 2. Authentication
- `POST /auth/login`
- `POST /auth/logout`
- `GET /auth/me`
- password reset flows
- MFA endpoints when enabled

## 3. Setup
- `GET/PUT /store/settings`
- `GET/POST /fiscal-years`
- `GET/POST /accounting-periods`
- `POST /accounting-periods/{id}/lock`
- currencies/exchange rates
- document sequences

## 4. Catalog
- `/categories`
- `/brands`
- `/products`
- `/products/{id}/barcodes`
- `/products/{id}/images`
- `/products/{id}/serials`
- `/warranty-policies`

## 5. Inventory
- `GET /inventory/availability`
- `GET /inventory/movements`
- stock transfers CRUD + `POST /{id}/post`
- stock adjustments CRUD + approval/post
- stock counts CRUD + finalize/post
- serial lookup/history

## 6. Suppliers/Purchasing
- `/suppliers`
- `/purchase-orders`
- `/goods-receipts`
- `/supplier-invoices`
- `/supplier-credit-notes`
- `/supplier-payments`
- supplier statement/aging

## 7. Customers/Sales
- `/customers`
- `/customers/{id}/statement`
- `/customers/{id}/credit-summary`
- `/sales-orders`
- `/sales-invoices`
- `POST /sales-invoices/{id}/post`
- `/sales-returns`
- `/sales-credit-notes`

## 8. Installments
- `POST /installment-contracts/preview-schedule`
- CRUD draft contract
- `POST /installment-contracts/{id}/submit-approval`
- `POST /installment-contracts/{id}/activate`
- `GET /installment-contracts/{id}/schedule`
- `POST /installment-contracts/{id}/reschedule-preview`
- reschedule approval/commit
- overdue/search endpoints

## 9. Payments
- `POST /customer-payments`
- `POST /customer-payments/{id}/post`
- allocations endpoints
- receipt PDF/download endpoint generated synchronously or queued depending on complexity

## 10. Checks
- `/checks`
- `POST /checks/{id}/deposit`
- `POST /checks/{id}/mark-cleared`
- `POST /checks/{id}/mark-bounced`
- `POST /checks/{id}/replace`
- `/check-deposit-batches`
- due/bounced dashboard endpoints

## 11. Accounting
- `/accounts`
- `/journals`
- `/journal-entries`
- `POST /journal-entries/{id}/post`
- `POST /journal-entries/{id}/reverse`
- trial balance, GL, income statement, balance sheet
- VAT registers/summary

## 12. Reports
Heavy reports should use async export pattern:
1. `POST /report-exports` returns export ID.
2. queued job generates file.
3. `GET /report-exports/{id}` returns status/progress.
4. `GET /report-exports/{id}/download` downloads when ready.

## 13. Idempotency
Critical POSTs accept `Idempotency-Key`. Duplicate request with same key returns original result rather than duplicating money/stock movement.

## 14. Validation/Authorization
Every endpoint declares policy/permission requirements. Frontend hidden buttons are convenience only; backend remains authoritative.
