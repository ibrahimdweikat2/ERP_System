# Product Requirements Document (PRD)

## 1. Product Definition
A single-store ERP for a Palestinian home electrical appliances retailer. All sales happen in the physical shop and are entered by authorized staff. The solution supports cash sales and installment sales, including installment collections by cash, check, or bank payment.

## 2. Actors
### Owner / Super Admin
Full control, financial visibility, approvals, configuration, period closing, user management and sensitive overrides.

### Accountant
Accounting, journal review/posting where allowed, VAT reports, bank/cash reconciliation, AP/AR, period close preparation and financial reports.

### Sales / Cashier
Create customer sales, receive allowed payments, print invoices/receipts, limited customer access. No unrestricted cost/profit access.

### Inventory / Purchasing User
Products, suppliers, purchase orders, receiving, stock transfers/adjustments subject to permissions.

### Collection User (optional role)
Installment follow-up, receipts and check status operations within restricted permissions.

## 3. Functional Scope
### A. Identity & Store Settings
- Pre-created platform/shop account.
- Login/logout/password reset.
- Optional MFA for owner/accountant.
- Store profile and legal/tax information.
- Fiscal year and accounting periods.
- Number sequences.
- Currency and exchange-rate configuration.

### B. Catalog
- Categories and nested categories.
- Brands.
- Units of measure.
- Product models/variants.
- SKUs, barcodes, specifications, images.
- Cash price, installment price, minimum allowed price.
- VAT category.
- Warranty policies.
- Serial tracking flags.

### C. Inventory
- Multiple internal stock locations in the single store context.
- Receiving, sale issue, customer return, supplier return, transfer, adjustment, damaged/open-box/warranty stock.
- Serial number lifecycle.
- Stock counts.
- Low/reorder stock alerts.
- Costing and stock valuation.

### D. Purchasing
- Suppliers.
- Purchase orders.
- Goods receipts.
- Supplier invoices.
- Supplier returns and credit notes.
- Supplier payments.
- AP aging.
- Payment proof attachments for policies/legal evidence where required.

### E. Sales / POS
- In-store order creation.
- Search by barcode/SKU/name/model/brand/serial.
- Customer selection or walk-in cash customer.
- Cash and installment sale modes.
- Discount controls.
- Invoice and receipt generation.
- Returns/exchanges and credit notes.

### F. Installments
- Down payment.
- Equal or custom schedule.
- Frequency: monthly by default, extensible to custom interval.
- Contract status and approval.
- Partial installment payment.
- Early settlement.
- Rescheduling with owner approval.
- Overdue tracking.
- Allocation of payments to installments.

### G. Checks
- Check instrument as a first-class entity.
- Post-dated checks per installment or as customer account settlement.
- Received, due, deposited, under collection, cleared, bounced, replaced, cancelled/returned.
- Check deposit batches.
- Return reasons and fees.
- Replacement check link.
- Customer check history and risk indicators.

### H. Accounting
- Double-entry general ledger.
- Configurable Chart of Accounts.
- Journals and journal entries.
- AR/AP subledgers.
- Inventory/COGS posting.
- Cash/bank/check accounts.
- VAT input/output.
- Expenses.
- Period locking and reversal model.

### I. Reports
Financial, VAT, sales, purchases, inventory, installment, checks, customers, suppliers, owner analytics and audit reports.

## 4. Non-Functional Requirements
- Correctness of accounting postings is more important than UI convenience.
- All monetary values use decimal columns; never floating point.
- Timezone defaults to Palestine and remains configurable.
- Arabic RTL UI supported throughout.
- API validation required server-side even if frontend validates.
- Long reports and exports use Laravel Queue with the database queue driver.
- All sensitive write workflows run in DB transactions.
- Every posted financial document has immutable identifiers and audit history.
- No destructive delete of posted financial records.
- MySQL foreign keys and purposeful indexes mandatory.
- Daily backups plus verified restoration process.

## 5. Out of Scope
- Online storefront.
- Multi-branch consolidation.
- Multi-company holding structure.
- Manufacturing/MRP.
- Fleet.
- Complex CRM pipeline.
- Project management.
- Docker/containers.
- Redis/PostgreSQL.

## 6. Success Metrics
- Inventory discrepancy reduced and traceable.
- Installment aging available in seconds.
- Check exposure by due date/status available immediately.
- Daily cashier closing reconciles system vs physical cash.
- VAT summary generated from posted transactions without spreadsheet reconstruction.
- Owner can trace every number in dashboards back to source transactions.
