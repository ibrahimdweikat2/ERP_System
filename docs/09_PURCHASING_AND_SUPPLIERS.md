# Purchasing and Suppliers

## 1. Supplier Master
Fields include legal/trade name, tax ID, contacts, address, currency, payment terms, credit limit, bank info where needed, active status and notes.

## 2. Procurement Flow
```text
Purchase Order (optional but recommended)
 -> Approval if threshold exceeded
 -> Goods Receipt
 -> Supplier Invoice
 -> 2/3-way matching checks
 -> Accounts Payable
 -> Supplier Payment
```

Small-shop mode may permit direct Goods Receipt + Supplier Invoice without a PO, subject to permission.

## 3. Goods Receipt
Receiving user verifies:
- product/model;
- quantity;
- serial numbers;
- damage/condition;
- actual receiving location;
- supplier delivery reference.

## 4. Supplier Invoice
Capture:
- invoice number;
- invoice date;
- due date;
- supplier;
- currency/rate;
- taxable base;
- VAT;
- total;
- attachments;
- related receipts/PO.

Prevent accidental duplicate supplier invoice numbers per supplier.

## 5. Accounts Payable
Supplier statement:
- opening balance;
- invoices;
- credit notes;
- payments;
- allocations;
- outstanding balance;
- aging.

## 6. Supplier Payment
Payment methods: cash, bank, check where business supports issuing checks. User selects invoices or allows oldest-first allocation. Payment posts GL and closes AP amounts.

## 7. Purchase Return
Reference original receipt/invoice where possible. Select exact serialized units. Inventory, supplier payable/credit and VAT must be adjusted through controlled posting.

## 8. Landed Cost
Optional but designed into target:
- transportation;
- shipping;
- customs/import charges;
- handling;
- other directly attributable cost.

Allocation bases: quantity, value, weight, manual. Result changes inventory unit cost only through an auditable landed-cost document.

## 9. Controls
- purchase price variance warning;
- approval for high-value PO;
- duplicate invoice detection;
- mandatory attachment/payment proof when policy requires;
- restricted backdating into closed periods.
