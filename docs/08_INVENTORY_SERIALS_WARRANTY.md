# Inventory, Serial Tracking and Warranty

## 1. Inventory Model
Use a perpetual stock movement ledger. Never let normal screens directly edit `products.quantity`.

Movement types:
- opening balance;
- purchase receipt;
- purchase return;
- sale issue;
- sales return;
- internal transfer;
- adjustment gain/loss;
- damaged write-off;
- warranty movement;
- reservation/release where reservation design affects availability.

## 2. Internal Locations
Single store can still have multiple locations:
- Showroom.
- Main Warehouse.
- Reserved for Customer.
- Returns Inspection.
- Damaged/Open Box.
- Warranty/Service.

They are **not branches** and do not require branch accounting.

## 3. Serial Tracking
Serialized appliance flow:
1. purchase receiving creates/registers serial;
2. serial is assigned to a stock location;
3. internal transfers update serial location/history;
4. sale line must select exact available serial;
5. sale links serial to customer and invoice;
6. return updates state and inspection disposition;
7. warranty claim references exact serial.

Serial statuses:
`in_stock, reserved, sold, returned_pending_inspection, damaged, warranty_service, supplier_returned, retired`.

## 4. Negative Stock
Default: prohibited. Owner may optionally enable controlled negative stock for non-serialized consumables, but serialized products can never be sold without an actual available serial.

## 5. Costing
Recommended default: moving weighted average for general stock valuation. Serialized record keeps actual receipt/unit cost for margin traceability. Document the chosen GL valuation policy and keep it consistent.

## 6. Stock Count
Cycle/full stock count:
- freeze or snapshot expected quantity;
- count physical items;
- scan serials;
- identify missing/unexpected serials;
- calculate variance value;
- require approval above threshold;
- post adjustment movement and accounting entry.

## 7. Reorder
Product fields:
- minimum level;
- reorder point;
- preferred supplier;
- lead-time days.

Report suggests purchase quantities but does not auto-purchase without approval.

## 8. Warranty
Warranty can be:
- store-provided;
- supplier/manufacturer-provided.

At sale, compute start/end date based on policy and preserve it on serial/customer sale record even if product policy later changes.

Warranty claim lifecycle:
`opened -> inspected -> sent_to_supplier/service -> repaired/replaced/rejected -> returned_to_customer -> closed`.

## 9. Returns Disposition
Returned serialized appliance must be inspected and classified:
- resalable unopened;
- open-box/resalable with new price;
- damaged;
- warranty/service;
- return to supplier.

Do not automatically return every customer return to sellable stock.
