# Data Migration and Go-Live

## 1. Migration Scope
If the shop currently uses spreadsheets/another system, migrate only validated master/opening data rather than importing years of corrupt operational history blindly.

Typical import:
- categories/brands/products;
- suppliers/customers;
- current stock and serialized units;
- current customer receivables/installment balances;
- current supplier payables;
- active/post-dated checks;
- cash/bank opening balances;
- fixed assets if included.

## 2. Opening Balances
All opening financial amounts must reconcile through an Opening Journal dated before operational go-live. Opening stock must reconcile quantity and valuation to Inventory GL.

## 3. Active Installment Migration
For each contract import:
- original reference;
- customer;
- remaining balance;
- future schedule;
- already-paid summary/history as required;
- related held checks;
- opening AR mapping.

Avoid reconstructing fake historical sales invoices unless the business/accountant explicitly requires them.

## 4. Serial Migration
Physical scan/count recommended. Each serial must resolve to one product and one location/status. Duplicate/unknown serials are exception report items.

## 5. Cutover Checklist
- freeze old-system transactions;
- final backup/export;
- import opening data;
- physical stock/serial verification;
- AR/AP confirmations;
- check custody verification;
- cashbox count;
- bank balances;
- accountant signs off opening Trial Balance;
- owner signs off customer/supplier exposure;
- enable normal posting.

## 6. First Closing
Perform first daily close with owner/accountant present. Reconcile cash, invoices, stock movements, installments and checks. Resolve configuration issues immediately rather than allowing manual workarounds.
