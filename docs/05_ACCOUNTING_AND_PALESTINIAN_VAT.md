# Accounting and Palestinian VAT

> This specification is a software design based on currently available Palestinian legal/financial references. Before production go-live, the shop's licensed accountant/CPA should review the configured Chart of Accounts, invoice fields, VAT treatment and reporting mappings.

## 1. Accounting Model
Accrual-oriented double-entry accounting with integrated operational subledgers.

### Core ledgers
- General Ledger.
- Accounts Receivable.
- Accounts Payable.
- Inventory/COGS.
- Cash.
- Banks.
- Post-dated checks / checks under collection.
- VAT input/output/payable.
- Expenses/assets/equity.

## 2. Suggested Chart of Accounts Skeleton
### Assets
- Cash on Hand
- Bank Accounts
- Checks Receivable / Post-Dated Checks
- Checks Under Collection
- Accounts Receivable — Customers
- Inventory
- Prepaid Expenses
- Fixed Assets
- Accumulated Depreciation (contra)

### Liabilities
- Accounts Payable — Suppliers
- VAT Payable / Output VAT
- Other Payables
- Accrued Expenses

### Equity
- Owner Capital
- Owner Drawings
- Retained Earnings

### Revenue
- Appliance Sales
- Other Operating Revenue
- Installment-related revenue/markup account according to approved accounting policy
- Sales Returns/Allowances (contra)

### Cost / Expenses
- Cost of Goods Sold
- Rent
- Electricity
- Salaries
- Transportation
- Maintenance
- Bank Fees
- Returned Check Fees
- Marketing
- Depreciation
- Other Operating Expenses

## 3. VAT Configuration
The platform must use a tax-code table with effective dates rather than a hard-coded percentage. The current Palestinian VAT law and later amendments must be represented by configuration that can be changed prospectively without altering old posted transactions.

Each invoice line stores:
- tax code;
- taxable base;
- rate actually applied;
- tax amount;
- tax inclusive/exclusive flag;
- tax transaction record.

Historic invoices must never recalculate when the current configured rate changes.

## 4. VAT Registers
- Output VAT register (sales).
- Input VAT register (purchases/eligible expenses).
- Credit/debit note adjustments.
- Exempt/zero-rated tracking where applicable.
- Period summary.
- Source-document drilldown.

## 5. Period Closing
Statuses:
- Open: normal posting.
- Soft Closed: ordinary staff cannot post; accountant/owner may reopen with reason.
- Locked: no alteration of posted entries; corrections occur in a later open period using reversal/adjustment.

## 6. Currency
Base/accounting/tax presentation currency should default to ILS. Transactions may occur in ILS/USD/JOD.

Store on every foreign currency posting:
- transaction currency;
- foreign amount;
- exchange rate;
- base currency amount;
- source/date of rate where policy requires.

Do not overwrite old rates.

## 7. Document Integrity
Posted invoices and entries:
- cannot be hard-deleted;
- cannot have financial fields silently edited;
- corrections use credit note, reversal or approved adjustment;
- document number remains permanent;
- audit trail records cancellation/reversal reason.

## 8. Sales Recognition
When goods are delivered/sold, recognize sale and output VAT according to configured policy. Installment collection timing must not be used to postpone the original sale posting merely because cash will be collected later.

## 9. Inventory Accounting
Perpetual inventory recommended:
- Sale posts COGS and reduces Inventory.
- Purchase/receipt/invoice flow posts Inventory and AP according to receiving/invoice matching policy.
- Return reverses appropriate portion.

Preferred valuation for this store: **moving weighted average** unless accountant/business chooses FIFO. Serialized units preserve unit acquisition cost for traceability even when GL uses selected valuation policy.

## 10. Manual Journals
Allowed only to authorized accounting roles. Control accounts (AR, AP, Inventory, VAT, checks) should normally reject manual lines unless explicitly approved, preventing subledger/GL divergence.

## 11. Reconciliation
Required periodic reconciliations:
- AR subledger vs GL control account.
- AP subledger vs GL.
- Inventory valuation vs Inventory GL.
- Cashbox session vs Cash GL.
- Bank statement vs Bank GL.
- Check register vs check GL accounts.
- VAT transaction register vs VAT GL.

## 12. Records Retention
Design for at least seven years of financial/audit document retention to remain conservative across applicable bookkeeping/tax record requirements. Deletion policies must exclude records under legal/financial retention.
