# ERP Master Documentation — Single-Store Home Electrical Appliances Shop (Palestine)

## 1. Purpose
This documentation defines a production-grade ERP for **one Palestinian retail shop selling home electrical appliances**. The system is intentionally designed as a **single-store ERP**, not multi-company and not multi-branch. It combines retail sales, serial-controlled inventory, suppliers and purchasing, installment sales, post-dated checks, customer receivables, Palestinian-oriented accounting/VAT, cash/bank management, warranty, returns, approvals, reporting, and auditability.

## 2. Mandatory Technology Stack
- Backend: **Laravel** (modular monolith, REST API).
- Database: **MySQL 8+**.
- Queue: **Laravel Queue using the database driver** stored in MySQL.
- Frontend: **React + TypeScript + Tailwind CSS**.
- API auth: Laravel Sanctum (SPA) or equivalent first-party Laravel token/session approach.
- File storage: local filesystem initially, with abstraction that permits later migration to S3-compatible storage without redesign.
- Scheduled tasks: Laravel Scheduler executed by OS cron / Windows Task Scheduler.

### Explicitly excluded
- Docker.
- Redis.
- PostgreSQL.
- Multi-company.
- Multi-branch.
- E-commerce storefront / customer online checkout.
- Manufacturing/MRP.
- Unrelated enterprise modules that do not serve this shop.

## 3. Design Principles
1. **Accounting is the source of financial truth.** Sales and payments post double-entry journals.
2. **Inventory is movement-ledger based.** Current quantity is derived/maintained from immutable stock movements, not arbitrary quantity edits.
3. **Installment is a receivable contract, not a payment method.** Cash/check/bank payments settle installments.
4. **A check is a financial instrument with a lifecycle**, not merely a string on a payment row.
5. **Physical appliances can be individually traceable** through serial numbers.
6. **Posted financial documents are never silently deleted.** They are reversed/cancelled with audit trail.
7. **Configuration over hard-coding** for VAT, accounts, document sequences, credit rules, installment policies, and fiscal settings.
8. **Owner control** through fine-grained permissions, approvals, audit logs, and period locking.
9. **Arabic-first capable UI**, with RTL support and English-ready data structures.
10. **Performance by design**, particularly for ledgers, receivables aging, inventory valuation, installments, checks, and VAT reports.

## 4. Primary Business Flow
```text
Account Created
  -> Store Setup Wizard
  -> Fiscal / Accounting Setup
  -> Categories & Brands
  -> Products / Models / Serial Tracking
  -> Suppliers
  -> Opening Stock & Opening Balances
  -> Ready for Operations

Purchasing -> Receiving -> Inventory -> Supplier Payable -> Supplier Payment

Inventory -> In-store Sale -> Cash OR Installment
                         -> Invoice -> Accounting Posting

Installment Contract -> Schedule -> Collection -> Cash / Check / Bank
Check -> Received -> Due -> Deposited -> Cleared OR Bounced
Bounced -> Customer Receivable Reinstated -> Follow-up / Replacement
```

## 5. Documentation Map
- `01_PRODUCT_REQUIREMENTS.md` — scope, actors, functional/non-functional requirements.
- `02_TECHNICAL_ARCHITECTURE.md` — backend/frontend architecture and domain boundaries.
- `03_STORE_SETUP_AND_MASTER_DATA.md` — first-use onboarding and master data.
- `04_DATABASE_DESIGN.md` — schema domains, tables, important fields and relationships.
- `05_ACCOUNTING_AND_PALESTINIAN_VAT.md` — double-entry model, tax rules, periods and controls.
- `06_INSTALLMENT_ENGINE.md` — contracts, schedules, payment allocation, rescheduling and defaults.
- `07_CHECK_MANAGEMENT.md` — post-dated checks, deposits, clearing, returns, replacement and controls.
- `08_INVENTORY_SERIALS_WARRANTY.md` — stock ledger, serial numbers, valuation, warranty and stock counts.
- `09_PURCHASING_AND_SUPPLIERS.md` — procurement and AP lifecycle.
- `10_SALES_POS_AND_RETURNS.md` — in-store sales, invoicing, discounts, returns and exchanges.
- `11_CUSTOMERS_AND_CREDIT_CONTROL.md` — customer files, limits, risk, aging and collection.
- `12_CASH_BANK_EXPENSES_AND_CLOSING.md` — cashboxes, banks, expenses and cashier sessions.
- `13_API_DESIGN.md` — API conventions and endpoint catalog.
- `14_UI_UX_INFORMATION_ARCHITECTURE.md` — menus, pages, UX and forms.
- `15_ROLES_PERMISSIONS_APPROVALS.md` — RBAC, approvals and sensitive operations.
- `16_REPORTING_AND_DASHBOARDS.md` — operational, accounting, VAT, installment and owner reports.
- `17_SECURITY_AUDIT_BACKUP.md` — application security, audit, backup and recovery.
- `18_PERFORMANCE_MYSQL_AND_QUEUE.md` — indexing, Laravel database queue, caching strategy without Redis.
- `19_NOTIFICATIONS_AND_SCHEDULER.md` — reminders and scheduled jobs.
- `20_DEPLOYMENT_NO_DOCKER.md` — traditional server deployment.
- `21_IMPLEMENTATION_PLAN.md` — phased build sequence.
- `22_ACCEPTANCE_CRITERIA.md` — release criteria and business acceptance.
- `23_ACCOUNTING_EXAMPLES.md` — realistic journal examples.
- `24_CODEX_IMPLEMENTATION_PROMPT.md` — a single implementation prompt for an AI coding agent.
- `25_LEGAL_AND_MARKET_REFERENCES.md` — authoritative references and legal assumptions.

## 6. Product Goal
The owner should be able to answer, at any moment:
- How much did the shop sell today/month/year?
- What is gross profit and net profit?
- What stock exists physically and what is its value?
- Which exact serialized appliance was sold to which customer?
- How much do customers owe the shop?
- Which installments are due/late?
- Which checks are due, deposited, cleared, or bounced?
- How much does the shop owe suppliers?
- How much cash and bank balance is represented in the ERP?
- What VAT is payable/recoverable for the current period?
- Which employees changed, cancelled, discounted or approved financial transactions?

## 7. MVP vs Full ERP
This documentation defines the **full target architecture**, but implementation is phased. The first production release must already be financially coherent: Store Setup, Catalog, Inventory, Purchasing, Sales, Customers, Installments, Checks, Core Accounting, Cash/Bank, VAT configuration, Roles, Audit, and essential reports. HR/payroll can follow after the financial core stabilizes.
