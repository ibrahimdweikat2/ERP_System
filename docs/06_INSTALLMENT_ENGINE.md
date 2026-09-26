# Installment Engine

## 1. Core Concept
An installment contract is a receivable agreement created from a posted/approved sale. **Installment is not a payment method**. Payments are separate and are allocated to the contract schedule.

## 2. Contract Inputs
- Customer.
- Sales invoice/order.
- Cash price.
- Installment price.
- Installment markup/difference.
- Down payment.
- Financed amount.
- Number of installments.
- Frequency.
- First due date.
- Schedule generation rule.
- Rounding rule.
- Grace days.
- Notes/terms.
- Required approval when policy thresholds are exceeded.

## 3. Schedule Types
### Equal Installments
System divides financed amount according to count and places rounding difference in final installment.

### Custom Schedule
Authorized user can set non-equal installment amounts/dates before approval.

### Balloon/Final Payment
Larger last installment supported.

## 4. Contract States
`draft -> pending_approval -> active -> completed`
Additional exceptional states:
`cancelled, defaulted, restructured`.

## 5. Schedule Item States
- Upcoming.
- Due.
- Partially Paid.
- Paid.
- Overdue.
- Rescheduled/Voided (historical trace retained).

Status should be derived from due date + remaining amount wherever possible, not manually toggled.

## 6. Payment Allocation
A customer payment can be allocated to:
1. selected installment(s), or
2. oldest due first by policy.

Store explicit allocation records. Never infer allocation later from totals.

### Partial payment
If installment is 500 ILS and customer pays 300:
- paid_amount = 300;
- remaining = 200;
- status = Partially Paid / Overdue depending on date.

## 7. Down Payment
Down payment is a normal customer payment posted on sale/contract activation and allocated against the customer receivable. It must have a receipt and payment method.

## 8. Early Settlement
System calculates:
- outstanding receivable;
- any approved discount/rebate for early settlement;
- resulting adjustment/credit note if required by accounting policy;
- owner approval when discount exceeds threshold.

## 9. Rescheduling
Never overwrite the old schedule.
Process:
1. capture reason;
2. store original schedule snapshot/version;
3. request approval;
4. create new schedule version;
5. preserve already-paid allocations;
6. link old schedule rows to replacement rows or mark superseded;
7. audit all changes.

## 10. Overdue Logic
Nightly scheduler updates/derives aging buckets and reminders:
- 1–7 days;
- 8–30 days;
- 31–60 days;
- 61–90 days;
- 90+ days.

Do not mutate accounting merely because an installment became overdue; this is collection/risk state.

## 11. Customer Credit Policy
Before activating a new contract evaluate:
- current AR;
- active installment financed balance;
- overdue balance;
- bounced check count/value;
- configured credit limit;
- maximum active plans;
- minimum down-payment policy;
- manual risk flag.

Result can be:
- allowed;
- warning requiring approval;
- blocked except owner override.

## 12. Printing
Installment contract printout should include customer, invoice, financed total, down payment, schedule, payment terms, signatures and identifiers. Keep print template configurable and legal text reviewed by the shop's legal/accounting advisor.
