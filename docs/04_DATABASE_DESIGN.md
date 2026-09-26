# Database Design — MySQL

## 1. Rules
- MySQL 8+ / InnoDB.
- `utf8mb4` everywhere.
- Primary keys: BIGINT UNSIGNED unless UUID/ULID is strategically selected project-wide.
- Monetary fields: `DECIMAL(18,4)` or context-specific decimal; never FLOAT/DOUBLE.
- Exchange rates: `DECIMAL(18,8)`.
- Quantity: `DECIMAL(18,4)`.
- Use foreign keys for core integrity.
- Use soft deletes only for master data where historical references remain safe; do not soft-delete posted journals as a substitute for reversal.
- Add `created_by`, `updated_by` where business attribution matters; central audit log still required.

## 2. Identity / Configuration Tables
### users
`id, name, email, phone, password, status, last_login_at, mfa_enabled_at, timestamps`

### roles / permissions / model_has_roles / role_has_permissions
Fine-grained RBAC.

### store_settings
Single active store record or key/value settings table with typed schema.

### fiscal_years
`id, name, starts_on, ends_on, status`

### accounting_periods
`id, fiscal_year_id, period_no, starts_on, ends_on, status(open|soft_closed|locked), locked_at, locked_by`

### currencies
`code, name, decimal_places, is_active, is_base`

### exchange_rates
`id, currency_code, rate_date, rate_to_base, source, locked`

### document_sequences
`id, document_type, prefix, year_pattern, next_number, padding, reset_policy`

## 3. Catalog Tables
### categories
`id, parent_id, name_ar, name_en, slug, active, sort_order`

### brands
`id, name_ar, name_en, logo_path, active`

### products
Core product/model fields including pricing, tax category and tracking policy.

### product_barcodes
Multiple barcodes per product.

### product_attributes / attribute_values / product_attribute_values
Structured specifications.

### product_images
Image metadata.

### warranty_policies
`duration_value, duration_unit, provider_type, terms`

## 4. Inventory Tables
### stock_locations
Internal locations: showroom, main warehouse, damaged, returns, warranty, reserved.

### inventory_movements
Immutable movement ledger:
`id, product_id, location_id, direction, quantity, unit_cost, total_cost, movement_type, source_type, source_id, source_line_id, occurred_at, posted_at`

### inventory_balances
Optional maintained summary for fast reads:
`product_id, location_id, qty_on_hand, qty_reserved, qty_available, average_cost, updated_at`
Updated only through domain posting actions.

### serial_numbers
`id, product_id, serial_no, status, current_location_id, purchase_receipt_line_id, sold_sales_line_id, customer_id, warranty_start, warranty_end`
Unique index on normalized serial number according to business rule.

### serial_movements
History of each serial across receipts, transfers, sales, returns and warranty.

### stock_counts / stock_count_lines
Physical count and variance approval.

### stock_transfers / stock_transfer_lines
Movement between internal locations.

### stock_adjustments / stock_adjustment_lines
Controlled write-off/correction with approval threshold.

## 5. Supplier / Purchasing Tables
### suppliers
Identity, tax fields, currency, payment terms, credit limit, status.

### purchase_orders / purchase_order_lines
PO lifecycle.

### goods_receipts / goods_receipt_lines
Physical receipt. Serial capture occurs here for serialized products.

### supplier_invoices / supplier_invoice_lines
Financial invoice, VAT and due dates.

### supplier_credit_notes / lines
Returns/credits.

### supplier_payments
Payment header, currency, exchange rate, bank/cash account.

### supplier_payment_allocations
Maps payment to one or many supplier invoices.

## 6. Customer / Sales Tables
### customers
Identity/contact, national/business ID fields, address, credit policy, status, risk flag.

### customer_contacts
Optional multiple contacts.

### sales_orders / sales_order_lines
In-store transaction draft/approval/order state.

### sales_invoices / sales_invoice_lines
Posted tax/financial sales invoice.

### sales_returns / sales_return_lines
Customer return transaction.

### sales_credit_notes / lines
Financial adjustment.

## 7. Installment Tables
### installment_contracts
`id, contract_no, customer_id, sales_invoice_id, original_amount, down_payment, financed_amount, installment_markup, total_contract_amount, installment_count, frequency, first_due_date, status, approved_by, approved_at`

### installment_schedule
`id, contract_id, sequence_no, due_date, principal_amount, markup_amount, installment_amount, paid_amount, remaining_amount, status, settled_at`

### installment_reschedules
Old/new schedule snapshot, reason, requested_by, approved_by.

### payment_allocations
General mapping from customer payments to invoices/installments.

## 8. Payment / Check Tables
### customer_payments
`id, receipt_no, customer_id, payment_date, amount, currency, exchange_rate, method, cashbox_id, bank_account_id, status, posted_journal_entry_id`

### checks
`id, check_no, customer_id, payer_name, bank_id, bank_branch_text, amount, currency, issue_date, due_date, received_date, status, source_payment_id, deposited_at, cleared_at, bounced_at, return_reason_id, replacement_check_id`

### check_allocations
A check can be allocated to one or more installment/invoice obligations when policy permits.

### check_status_history
Every state transition with user, timestamp and note.

### check_deposit_batches / check_deposit_batch_items
Group checks deposited to a bank account.

### check_return_reasons
Configurable reference list.

## 9. Accounting Tables
### accounts
Chart of Accounts:
`id, code, name_ar, name_en, parent_id, account_type, normal_balance, is_control_account, allow_manual_posting, active`

### journals
Sales, Purchases, Cash Receipts, Cash Payments, Bank, General, Inventory, Checks, Opening.

### journal_entries
`id, entry_no, journal_id, entry_date, fiscal_period_id, reference_type, reference_id, description, status, posted_by, posted_at, reversal_of_id`

### journal_lines
`journal_entry_id, account_id, customer_id nullable, supplier_id nullable, debit, credit, currency, foreign_amount, exchange_rate, memo`
Constraint in service layer: total debit == total credit.

### tax_codes
Rate/effective dates and posting accounts.

### tax_transactions
Detailed VAT basis per source line for audit/reporting.

## 10. Cash / Bank / Expenses
### cashboxes
Cashbox/account link.

### bank_accounts
Bank identity, IBAN/account no if applicable, currency, GL account.

### cashier_sessions
Opening/closing balance and variance.

### expenses / expense_lines
Expense voucher, category/account, tax, payee, attachments.

## 11. Warranty
### warranty_claims
Customer/serial/problem/status/vendor/repair data.

### warranty_claim_events
Timeline.

## 12. Workflow / Governance
### approvals
Generic approval request linked to source document/action.

### approval_steps
Who approved/rejected and reason.

### audit_logs
`actor_user_id, action, entity_type, entity_id, before_json, after_json, ip_address, user_agent, occurred_at`
Sensitive fields should be masked where necessary.

### attachments
Generic file metadata.

### idempotency_keys
Protect critical repeated writes.

## 13. Queue/Scheduler Tables
Laravel queue tables:
- `jobs`
- `job_batches` if batching used
- `failed_jobs`

Application scheduler logs optional:
- `scheduled_task_runs`

## 14. Critical Indexes
At minimum:
- invoice/document numbers unique;
- `inventory_movements(product_id, location_id, occurred_at)`;
- `serial_numbers(serial_no)` unique/normalized;
- `installment_schedule(status, due_date)`;
- `checks(status, due_date)`;
- `journal_entries(entry_date, status)`;
- `journal_lines(account_id, journal_entry_id)`;
- `customer_payments(customer_id, payment_date)`;
- `sales_invoices(customer_id, invoice_date, status)`;
- `supplier_invoices(supplier_id, invoice_date, status)`;
- audit entity composite index;
- queue `jobs(queue, reserved_at, available_at)` compatible with Laravel schema.

## 15. Concurrency Locks
Use `SELECT ... FOR UPDATE` / Eloquent `lockForUpdate()` when generating sequences, posting stock affecting limited serials, allocating payments, or moving check state where a double transition could occur.
