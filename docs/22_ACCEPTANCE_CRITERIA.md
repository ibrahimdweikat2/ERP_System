# Acceptance Criteria

## 1. General
- Single-store only; no branch selector/data leakage.
- MySQL is the only relational DB.
- No Docker/Redis/PostgreSQL dependencies.
- React + TypeScript + Tailwind frontend.
- Laravel database queue works with failed job handling.

## 2. Accounting
- Every posted journal balances exactly.
- Posted financial transactions are not hard-deletable.
- Reversal leaves full history.
- Locked periods reject new/modified posting.
- AR/AP/inventory/check control accounts reconcile to subledgers.

## 3. Inventory
- Serialized item cannot be sold twice.
- Serialized sale selects exact available serial.
- Return retains serial history.
- Inventory balance equals movement-ledger net quantity.
- Negative stock blocked according to policy.

## 4. Sales
- Cash sale posts inventory, COGS, revenue, VAT and cash/AR correctly.
- Discount/price override respects role/approval policy.
- Return creates auditable reversal/credit flow.

## 5. Installments
- Contract total/schedule exactly reconciles after rounding.
- Partial payments work.
- Down payment has a payment receipt/allocation.
- Rescheduling preserves prior schedule history.
- Overdue status is correct by date/remaining amount.
- Credit-limit/risk rules trigger expected warning/block.

## 6. Checks
- Check state machine rejects invalid transitions.
- Deposit/clear updates correct accounts.
- Bounce restores customer exposure according to configured accounting policy.
- Replacement check retains link to original.
- Due/bounced dashboards reconcile to check register.

## 7. Reports
- Dashboard totals drill down and reconcile with transactional reports.
- Trial balance debit=credit.
- Inventory valuation reconciles to GL under chosen policy.
- VAT report traces each amount to posted tax transaction/source document.

## 8. Security/Audit
- Unauthorized API calls fail even when manually invoked.
- Sensitive actions appear in audit log with actor/time/context.
- Backup can be restored successfully in rehearsal.

## 9. Performance
- Operational list endpoints paginate.
- Large exports run through queue and do not exhaust normal request timeout/memory.
- Core due-installment/check queries use indexed paths verified with MySQL query plans.
