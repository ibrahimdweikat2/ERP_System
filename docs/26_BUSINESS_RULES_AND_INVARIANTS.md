# Business Rules and System Invariants

These rules are mandatory across API, jobs, UI and database transactions.

## Financial Invariants
1. A posted journal entry always has total debit = total credit.
2. A posted journal entry is never edited in place; use reversal/adjustment.
3. A posted invoice's monetary/tax lines are immutable.
4. Control accounts cannot normally be posted by manual journals.
5. Customer AR equals customer subledger after reconciliation.
6. Supplier AP equals supplier subledger after reconciliation.
7. Check ledger balances equal check register by lifecycle state/accounting policy.
8. Locked accounting periods reject posting/editing/reversal dated inside the lock unless a formal controlled reopen process exists.

## Sales/Inventory Invariants
1. A serialized unit can only be in one current inventory state/location.
2. A sold serial cannot be sold again until a valid posted return returns it to eligible stock.
3. Stock changes only through stock movements/posting actions.
4. Posted sale has a corresponding inventory issue for stock items.
5. Posted return references original transaction where available and records exact serialized item.
6. An exchange is represented by auditable return + new sale rather than invisible substitution.

## Installment Invariants
1. Sum of active schedule amounts = contractual amount intended for collection after allowed adjustments.
2. Payment allocation sum cannot exceed posted customer payment amount.
3. An installment cannot have negative remaining balance.
4. Rescheduling never deletes old schedule history.
5. A contract cannot be completed while an outstanding contractual balance remains.
6. Credit override requires owner/authorized approval with reason.

## Check Invariants
1. Check state transitions follow state machine.
2. A cleared check cannot later be bounced without an explicit reversal/correction process.
3. A replacement check never deletes the original bounced check.
4. Check amount/currency are immutable after posting into a financial receipt unless reversed.
5. Deposit batch total equals its included check amounts grouped by currency.

## Currency Invariants
1. Base currency amount is frozen on posted entry.
2. Historical exchange rate cannot be silently changed on posted documents.
3. Currency rounding rules are centralized.

## Approval Invariants
1. Approval applies to a specific source version/payload.
2. Editing an approval-sensitive value after approval invalidates/requires a new approval.
3. User cannot approve their own request where segregation-of-duties rule is configured.

## Audit Invariants
1. Sensitive changes must have actor + timestamp + entity + action.
2. Audit records are append-only for normal application users.
3. Financial deletion attempts are logged and rejected.
