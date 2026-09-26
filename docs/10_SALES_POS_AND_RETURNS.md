# Sales, POS and Returns

## 1. Sales Context
All customer sales are created inside the shop by staff. No public e-commerce checkout is part of this ERP.

## 2. POS/Sales Screen Goals
Fast keyboard/barcode workflow with:
- search by barcode, SKU, model, Arabic/English name, brand;
- product cards/list with stock availability;
- serial selection for serialized product;
- customer selector;
- clear cash vs installment sale mode;
- totals, discounts and tax breakdown;
- permission-aware price override.

## 3. Walk-in Customer
Cash sale can use a configured walk-in customer when customer-specific identity is not required. Installment sales require a real customer profile and credit validation.

## 4. Sale States
`draft -> pending_approval(optional) -> confirmed/posted -> fulfilled`
Exceptional: `cancelled/reversed` through controlled flow.

## 5. Price Controls
Each product may have:
- cash list price;
- installment list price;
- minimum price;
- promotion/price list in future.

If price < minimum or discount > user's limit, create approval request.

## 6. Cash Sale
On completion:
- validate stock and exact serials;
- create/post sales invoice;
- record customer payment;
- create receipt if needed;
- reduce stock;
- post revenue/VAT/COGS/cash accounting.

## 7. Installment Sale
- real customer required;
- calculate cash price vs installment total;
- collect down payment if configured;
- create contract/schedule;
- validate credit policy;
- post sale and AR;
- print invoice + installment agreement/schedule.

## 8. Delivery/Fulfilment
If the shop delivers appliances later, sales invoice/order can track fulfilment status and reserved serial without becoming an e-commerce system. Optional fields: delivery address, delivery date, delivery fee, installer notes.

## 9. Sales Return
Return should reference original invoice when possible. Validate exact serial, return window and item condition. Result may create:
- credit/refund;
- exchange credit;
- reduction of customer AR;
- stock return to inspection location;
- VAT and COGS reversal according to original sale.

## 10. Exchange
Implement as return/credit plus new sale, linked for usability. Do not create opaque inventory swaps that bypass accounting.

## 11. Printing
Print templates:
- sales invoice;
- receipt;
- installment agreement/schedule;
- return/credit note;
- delivery slip if enabled.
