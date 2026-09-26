# Customers and Credit Control

## 1. Customer Profile
For installment customers capture enough data for collection and risk control:
- full name / business name;
- ID/tax number fields as relevant;
- phones;
- address;
- workplace/employer optional;
- contact person/guarantor fields only if business chooses and legal/privacy review permits;
- preferred currency;
- credit limit;
- maximum active installment contracts;
- maximum overdue days;
- risk flag;
- notes/attachments.

## 2. Customer Statement
One chronological statement including:
- invoices;
- returns/credit notes;
- cash/bank/check payments;
- check reversals/bounces;
- manual approved adjustments;
- running balance.

## 3. Aging
Buckets configurable, default:
- current/not due;
- 1–30;
- 31–60;
- 61–90;
- 90+ days.

Show both invoice aging and installment aging because they answer different questions.

## 4. Credit Decision
Before installment sale compute:
- total outstanding AR;
- financed balance not yet due;
- overdue total;
- oldest overdue days;
- active contracts count;
- checks held;
- bounced checks count/value;
- pending replacement checks;
- proposed new financed amount.

Rule engine returns pass/warn/block. Owner can override with recorded reason where policy allows.

## 5. Collections Workbench
Collection view sorted by priority:
- overdue amount;
- days overdue;
- upcoming check due;
- bounced check;
- customer contact info;
- last contact/action;
- next follow-up date.

Optional follow-up notes are operational only and do not modify accounting.

## 6. Customer Merge
If duplicate customer records appear, only owner/admin can merge through a dedicated tool that reassigns safe references and preserves audit. Never simply delete a customer with history.
