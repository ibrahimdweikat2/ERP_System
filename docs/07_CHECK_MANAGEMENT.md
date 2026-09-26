# Check Management

## 1. Purpose
Checks are common settlement instruments and must be managed as financial assets with clear due dates, custody and collection states.

## 2. Check Capture
Fields:
- check number;
- payer/account holder;
- customer;
- bank;
- branch text/code if needed;
- currency;
- amount;
- issue date;
- due date;
- received date;
- image/scan attachment;
- related receipt;
- related installment/invoice allocations;
- notes.

Validate duplicate check number using a business-aware composite key (bank/account holder or other identifiers when available); do not assume check number alone is globally unique.

## 3. Lifecycle
Recommended state machine:
```text
Received/Post-Dated
   -> Due
   -> Deposited
   -> Under Collection
      -> Cleared
      -> Bounced

Bounced -> Replaced (new linked check)
Bounced -> Settled by cash/bank
Received -> Returned to Customer / Cancelled (with approval)
```

Every transition writes `check_status_history`.

## 4. Accounting Lifecycle (Configurable Accounts)
Example policy:
- Upon accepted check: Dr Post-Dated Checks Receivable / Cr Customer AR.
- On deposit: Dr Checks Under Collection / Cr Post-Dated Checks Receivable.
- On clearing: Dr Bank / Cr Checks Under Collection.
- On bounce: Dr Customer AR / Cr Checks Under Collection, plus approved bank fee handling.

Some accountants may choose a different moment to credit AR. Therefore account mappings and policy must be configurable, but the lifecycle/events remain explicit.

## 5. Bounced Check
Capture:
- bounce date;
- return reason;
- bank fee;
- PMA-related classification note if manually known;
- follow-up owner/user;
- settlement deadline/status;
- replacement check if any.

The original bounced check remains historical even after replacement.

## 6. Deposit Batches
User selects due/eligible checks and creates a bank deposit batch. Batch shows:
- bank account;
- deposit date;
- list of checks;
- total by currency;
- status;
- proof/attachment.

## 7. Dashboards
- checks due today;
- due within 7/30 days;
- held by currency;
- deposited/under collection;
- bounced;
- bounced by customer;
- checks awaiting replacement;
- expected future cash-flow from checks.

## 8. Controls
- Only privileged roles can mark check cleared/bounced manually.
- Clearing/bounce is idempotent.
- Cannot clear a cancelled/bounced check.
- Cannot delete a check tied to posted receipt.
- Amount cannot be changed after financial posting; correction uses reversal/reissue flow.
- Due date change after receipt requires permission/audit and may require approval.
