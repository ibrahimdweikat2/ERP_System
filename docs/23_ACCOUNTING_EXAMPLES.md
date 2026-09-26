# Accounting Examples

> Account names are illustrative. Exact Palestinian tax/account configuration must be approved by the shop's accountant before production.

## Example 1 — Cash Purchase of Inventory
Supplier invoice: merchandise 10,000 ILS + VAT according to configured rate; paid later.

Posting concept:
```text
Dr Inventory / Purchases basis       10,000
Dr Input VAT                          X
    Cr Accounts Payable                  10,000 + X
```

On supplier cash/bank payment:
```text
Dr Accounts Payable                 10,000 + X
    Cr Cash/Bank                         10,000 + X
```

## Example 2 — Cash Sale of Serialized Refrigerator
Sale amount excluding VAT = S; VAT = V; inventory carrying cost = C.

Revenue/tax:
```text
Dr Cash / Customer Receivable       S + V
    Cr Sales Revenue                    S
    Cr Output VAT                       V
```

Inventory/COGS:
```text
Dr Cost of Goods Sold               C
    Cr Inventory                        C
```

Serial is marked sold and linked to invoice/customer.

## Example 3 — Installment Sale
Installment invoice total = 5,800 ILS; down payment = 800; balance = 5,000.

At sale, record full invoice receivable (with revenue/VAT split according to invoice):
```text
Dr Customer Receivable              5,800
    Cr Sales / Installment Revenue Basis ...
    Cr Output VAT                    ...
```

Down payment:
```text
Dr Cash                                800
    Cr Customer Receivable               800
```
Remaining AR = 5,000; schedule represents collection timing, not new sales.

## Example 4 — Cash Installment Collection
Customer pays 500:
```text
Dr Cash                                500
    Cr Customer Receivable               500
```
Payment allocation settles the selected schedule row.

## Example 5 — Post-Dated Check Received
Under one possible accounting policy:
```text
Dr Post-Dated Checks Receivable        500
    Cr Customer Receivable               500
```

## Example 6 — Check Deposited
```text
Dr Checks Under Collection             500
    Cr Post-Dated Checks Receivable      500
```

## Example 7 — Check Cleared
```text
Dr Bank                                500
    Cr Checks Under Collection           500
```

## Example 8 — Check Bounced
```text
Dr Customer Receivable                500
    Cr Checks Under Collection           500
```
If bank charges 20 ILS and policy charges business expense:
```text
Dr Bank Fees Expense                   20
    Cr Bank                              20
```
If fee is legally/contractually recoverable from customer, use approved receivable/revenue policy instead; do not invent it automatically.

## Example 9 — Sales Return
Reverse revenue/VAT as a credit note and restore inventory/COGS only according to returned item's accepted condition and original cost. A damaged return may enter a separate inventory location/value and require impairment/write-off.

## Example 10 — Owner Withdrawal
Do not post as shop expense:
```text
Dr Owner Drawings                     1,000
    Cr Cash                            1,000
```

## Reconciliation Principle
Every example must be verifiable from:
source document -> subledger -> journal entry -> general ledger -> report.
