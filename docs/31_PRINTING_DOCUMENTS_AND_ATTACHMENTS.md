# Printing, Documents and Attachments

## 1. Printable Documents
- Sales Invoice.
- Sales Return / Credit Note.
- Customer Receipt.
- Installment Agreement and Schedule.
- Check Receipt/List acknowledgement if desired.
- Purchase Order.
- Goods Receipt.
- Supplier Payment Voucher.
- Expense/Payment Voucher.
- Stock Transfer/Adjustment.
- Customer Statement.
- Supplier Statement.
- Check Deposit Batch.

## 2. Template Requirements
- Arabic RTL support.
- Store identity/logo/tax fields.
- permanent document number/date.
- currency/exchange rate when required by policy.
- VAT breakdown as configured.
- customer/supplier details according to document type.
- clear totals and status (e.g. VOID/CREDIT) for reversed/cancelled copies.

## 3. Attachments
Examples:
- supplier invoice scan;
- payment proof;
- check image;
- customer/installment documents;
- warranty receipt/photo;
- expense receipt.

Metadata:
`original_name, stored_path, mime_type, size, checksum, uploaded_by, entity_type, entity_id, visibility_classification`.

## 4. Access Control
Users only download attachments when authorized to view the parent entity. Do not expose direct public file paths.

## 5. Generated PDF Strategy
Simple receipts/invoices may generate synchronously. Large reports use queued generation. Generated report files have expiration metadata; original source documents/attachments follow retention rules.
