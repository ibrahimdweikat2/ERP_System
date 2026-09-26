# Reporting and Dashboards

## 1. Owner Dashboard
KPIs:
- sales today/month;
- gross profit;
- cash collected;
- installment collections;
- new installment financed amount;
- total AR;
- overdue AR;
- due installments today/7 days;
- checks due today/7 days;
- bounced checks;
- supplier payables;
- cashbox total;
- bank balances per ERP;
- inventory value;
- low-stock products;
- VAT estimate/period status.

Each KPI must drill down to source data.

## 2. Sales Reports
- daily/monthly sales;
- sales by product/category/brand;
- sales by employee;
- cash vs installment;
- discounts/overrides;
- returns;
- gross margin by sale/product/category/brand.

## 3. Inventory Reports
- stock by location;
- inventory valuation;
- serial inventory;
- serial history;
- slow-moving/dead stock;
- inventory aging;
- low/reorder stock;
- stock movement ledger;
- stock adjustment variance;
- open-box/damaged stock.

## 4. Installment Reports
- active contracts;
- schedule by due date;
- due today/week/month;
- overdue aging;
- collection performance;
- remaining financed balance;
- early settlements;
- reschedules;
- customer exposure.

## 5. Check Reports
- checks held;
- due by date;
- deposited/under collection;
- cleared;
- bounced;
- returned/replaced;
- customer bounced history;
- check cash-flow forecast by week/month/currency.

## 6. Purchasing/Supplier Reports
- purchases by supplier/product/brand;
- price change history;
- open PO;
- receipts not invoiced;
- AP aging;
- supplier statement;
- supplier payment history.

## 7. Accounting Reports
- Trial Balance.
- General Ledger.
- Account Ledger.
- Income Statement.
- Balance Sheet.
- Cash Flow / cash movement report.
- AR/AP reconciliation.
- journal register.

## 8. VAT Reports
- output VAT register;
- input VAT register;
- credit-note adjustments;
- period summary;
- taxable/exempt/zero-rated categories if configured;
- VAT transaction drilldown.

## 9. Export Strategy
Small reports render interactively. Large PDF/Excel/CSV exports use database-backed Laravel Queue and export status polling. Generated files expire according to retention policy; original accounting source data never expires.
