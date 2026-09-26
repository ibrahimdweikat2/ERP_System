# Cash, Bank, Expenses and Daily Closing

## 1. Cashboxes
Even one store may have multiple physical drawers/cashboxes. Each cashbox maps to a GL account.

Cash transactions:
- cash sale/receipt;
- installment collection;
- supplier payment;
- expense;
- cash transfer to bank;
- owner deposit/withdrawal;
- refund.

## 2. Cashier Sessions
A cashier can open a session with opening cash. Every cash movement should belong to an active session when cashier control is enabled.

Closing calculates:
`expected closing = opening + receipts - payments/refunds/transfers`

User enters actual counted amount. Variance requires explanation; large variance requires approval.

## 3. Bank Accounts
Bank account master includes currency and GL account. Operations:
- deposit;
- withdrawal;
- bank transfer;
- check deposit batch;
- bank fees;
- customer bank payment;
- supplier bank payment.

## 4. Bank Reconciliation
Import/manual statement lines can be phase 2, but target design includes reconciliation status and matched ERP transactions.

## 5. Expenses
Expense voucher fields:
- date;
- payee/vendor;
- category/GL account;
- description;
- amount/currency;
- VAT code where relevant;
- payment method;
- cashbox/bank;
- attachment;
- approval status.

## 6. Owner Drawings / Capital
Do not classify owner withdrawals as ordinary business expense. Use owner equity/drawings accounts.

## 7. Sensitive Controls
- no cash payment if session closed where session enforcement applies;
- no posting to locked period;
- approval thresholds for large expense/cash withdrawal;
- audit all voids/refunds;
- require reason for cash variance.
