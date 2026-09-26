# Legal and Market References

## Purpose
This file records the external references that informed the Palestine-specific design. It is not legal advice. Production configuration should be reviewed by a Palestinian accountant/CPA and, where necessary, legal counsel.

## Palestinian VAT
Official Palestinian legal portal (Maqam):
- Decision by Law No. 26 of 2024 regarding Value Added Tax. Effective 30 June 2025; shown by the official portal as current/amended.
- Decision by Law No. 11 of 2026 amending Decision by Law No. 26 of 2024 regarding VAT. Issued/effective 13 June 2026 and shown as in force.

Reference pages:
- https://mjr.ogb.gov.ps/Decrees/Details/33829/
- https://mjr.ogb.gov.ps/Decrees/Details/34270/

Software consequence:
- VAT rules/rates/templates must be effective-dated and configurable.
- Historical posted invoice tax must remain frozen.
- VAT reporting must be auditable to source transactions.

## Palestinian Monetary Authority — Checks
The Palestinian Monetary Authority operates a returned-check system containing information about customers whose checks are returned, return reasons and classifications. It notes return causes including insufficient funds and technical/signature issues.

Reference:
- https://www.pma.ps/check-inquiry

PMA also documents the Electronic Check Clearing (ECC) system and a standard clearing cycle for participating banks, reinforcing the need to represent deposit/collection/return lifecycle rather than treating a check as generic cash.

Reference:
- https://www.pma.ps/ecc

Software consequence:
- check state/history is a first-class domain;
- bounced-check reason/history matters to customer risk;
- due, deposited, under-collection, cleared and bounced states must be distinct.

## Market Pattern
Palestinian ERP/accounting products and implementations commonly integrate sales, inventory, accounting, customer/supplier balances, VAT and checks. This project specializes those patterns for a home-electrical-appliance retailer with serial-controlled inventory and installment receivables.

## Mandatory Go-Live Review
Before production:
1. accountant confirms chart of accounts;
2. accountant confirms VAT rate/effective date and invoice fields;
3. accountant confirms installment markup recognition/tax treatment;
4. accountant confirms post-dated-check journal policy;
5. accountant confirms retained-document policy and statutory reports;
6. legal advisor reviews installment agreement/customer-data wording if used.
