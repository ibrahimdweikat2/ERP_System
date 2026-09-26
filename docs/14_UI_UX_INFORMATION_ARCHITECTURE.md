# UI/UX Information Architecture

## 1. UX Direction
Modern professional ERP, Arabic-first RTL, dense enough for operational work but not visually cluttered. Avoid a consumer-style oversized-card dashboard. Use clear hierarchy, consistent tables, sticky filters and fast keyboard navigation.

## 2. Main Sidebar
### Dashboard
Owner / operational dashboard.

### Sales
- New Sale / POS
- Sales Orders
- Sales Invoices
- Returns & Credit Notes
- Customer Receipts

### Installments
- Contracts
- Due Today
- Overdue
- Collections
- Rescheduling Requests

### Checks
- Check Center
- Due Checks
- Deposit Batches
- Bounced Checks
- Replacement/Settlement Follow-up

### Customers
- Customer List
- Statements
- Credit/Risk

### Products
- Products
- Categories
- Brands
- Price Management
- Warranty Policies

### Inventory
- Stock Overview
- Serial Numbers
- Movements
- Transfers
- Stock Counts
- Adjustments
- Low Stock
- Damaged/Open Box

### Purchasing
- Suppliers
- Purchase Orders
- Goods Receipts
- Supplier Invoices
- Supplier Returns
- Supplier Payments

### Accounting
- Chart of Accounts
- Journal Entries
- General Ledger
- Receivables
- Payables
- Cashboxes
- Bank Accounts
- Expenses
- VAT
- Fiscal Periods

### Reports
- Sales
- Inventory
- Installments
- Checks
- Customers
- Suppliers
- Accounting
- VAT
- Profitability

### Administration
- Users
- Roles & Permissions
- Approvals
- Audit Log
- Store Settings
- Sequences
- Currencies/Exchange Rates
- Backup/health status where exposed

No branch menu or branch selector.

## 3. POS Layout
Desktop-first operational layout:
- top search/barcode field;
- product results area;
- current cart panel;
- customer and sale type prominently visible;
- serial selector appears when required;
- totals always visible;
- actions: hold draft, checkout, print;
- cash/installment checkout uses stepper/modal with clear accounting consequences.

## 4. Installment Contract Page
Sections:
- customer credit summary;
- sale summary;
- cash vs installment pricing;
- down payment;
- schedule builder;
- checks to be received if provided at sale;
- approval warnings;
- printable agreement preview.

## 5. Check Center
Table with strong date/status filters and summary totals. Columns: customer, check no, bank, amount, currency, due date, allocation, status, custody/deposit, actions. Bounced checks visually distinct without relying only on color.

## 6. Customer Detail Tabs
Overview, Statement, Installments, Checks, Purchases, Returns, Receipts, Attachments, Audit/notes.

## 7. Product Detail Tabs
Overview, Pricing, Stock by location, Serials, Purchase History, Sales History, Warranty, Movements, Attachments.

## 8. Accessibility
- keyboard-accessible controls;
- visible focus states;
- no color-only status meaning;
- proper labels and error descriptions;
- logical RTL table/action ordering;
- print styles independent from screen theme.

## 9. Responsive Scope
ERP optimized for desktop/laptop. Tablet should support receiving/stock count and basic operations. Phone support is secondary for dashboards/approvals, not full cashier workflow.
