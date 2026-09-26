# Implementation Plan

## Phase 0 — Foundation
1. Laravel API skeleton.
2. React + TypeScript + Tailwind frontend skeleton.
3. Auth/Sanctum.
4. RBAC/permissions.
5. MySQL conventions and migration baseline.
6. database queue tables/configuration.
7. audit framework.
8. document sequence service.

**Exit:** authenticated owner can access empty ERP shell; permissions/audit/queue infrastructure verified.

## Phase 1 — Store Setup & Accounting Foundation
1. store settings wizard;
2. fiscal years/periods;
3. currencies/exchange rates;
4. Chart of Accounts template;
5. journals and posting engine;
6. tax codes;
7. cashboxes/bank accounts.

**Exit:** balanced manual test journal can be posted/reversed, period rules work.

## Phase 2 — Catalog & Inventory
1. categories/brands;
2. products/attributes/barcodes/pricing;
3. stock locations;
4. inventory movement ledger;
5. balances;
6. serial numbers and history;
7. transfers/adjustments/counts;
8. warranty policies.

**Exit:** receiving/transfer/adjustment scenarios reconcile movements and balances.

## Phase 3 — Suppliers & Purchasing
1. suppliers;
2. POs;
3. goods receipts + serial capture;
4. supplier invoices/VAT;
5. AP posting;
6. supplier payments/allocation;
7. purchase returns/credits.

**Exit:** supplier statement and AP GL reconcile.

## Phase 4 — Customers & Sales
1. customer master;
2. POS/sales order UI;
3. sales invoice;
4. serial selection;
5. cash customer payment;
6. COGS/inventory accounting;
7. returns/credit notes.

**Exit:** cash sale updates stock, serial, revenue, VAT, cash and COGS correctly.

## Phase 5 — Installment Engine
1. credit summary;
2. schedule preview/generator;
3. contract lifecycle;
4. down payment;
5. payment allocation;
6. overdue logic;
7. rescheduling;
8. early settlement;
9. approval integration.

**Exit:** full sale-to-final-installment scenario reconciles customer AR to zero.

## Phase 6 — Checks
1. check master/lifecycle;
2. installment/check allocations;
3. due check dashboard;
4. deposit batches;
5. clearing;
6. bouncing;
7. replacement/settlement;
8. check accounting.

**Exit:** received->deposit->clear and received->deposit->bounce->replacement scenarios reconcile customer/check/bank GL.

## Phase 7 — Expenses, Cashier Closing, Bank
1. expense vouchers;
2. cashier sessions;
3. cash transfer;
4. bank transaction/reconciliation foundations;
5. approval thresholds.

## Phase 8 — Reporting & VAT
1. owner dashboard;
2. operational reports;
3. AR/AP aging;
4. inventory valuation;
5. GL/trial balance/P&L/balance sheet;
6. VAT registers/summary;
7. async export framework via database queue.

## Phase 9 — Security, Operations & Hardening
1. MFA option;
2. rate limits/security headers;
3. audit completeness;
4. backup automation/documentation;
5. restore rehearsal;
6. worker/process monitoring;
7. performance profiling/index tuning.

## Phase 10 — Optional ERP Extensions
- warranty claim workflow;
- HR/payroll if business decides;
- supplier landed cost enhancement;
- bank statement import;
- SMS/WhatsApp notification integration;
- barcode label printing.

## Implementation Rule
Do not begin a later financial domain before the preceding ledger/posting invariants are stable. The project must remain deployable after each phase.
