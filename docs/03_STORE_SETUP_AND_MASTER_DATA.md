# Store Setup and Master Data

## 1. First Login Wizard
The account already exists before the owner enters the platform. On first login, the ERP detects incomplete setup and redirects to a guided wizard.

### Step 1 — Store Identity
- Trade/store name.
- Legal name if different.
- Owner/contact.
- Address.
- Phone/email.
- Logo.
- Tax registration fields.
- Default invoice footer and terms.

### Step 2 — Locale and Currency
- Base/accounting currency: ILS by default.
- Transaction currencies: ILS, USD, JOD initially.
- Timezone: Palestine.
- Date/number formatting.
- Arabic-first interface preference.

### Step 3 — Accounting Foundation
- Fiscal year start/end.
- Chart of Accounts template.
- Cashbox accounts.
- Bank accounts.
- Accounts Receivable control account.
- Accounts Payable control account.
- Inventory and COGS accounts.
- Sales/revenue accounts.
- VAT input/output/payable accounts.
- Post-dated checks / checks under collection accounts.

### Step 4 — Tax
- VAT registration status.
- Default VAT rate/configuration.
- Tax-inclusive vs tax-exclusive default price display.
- Invoice sequence.

### Step 5 — Catalog Masters
Owner creates categories, brands and attributes available in the shop.

### Step 6 — Suppliers and Products
Initial suppliers and product catalog.

### Step 7 — Opening Inventory
Opening stock by product/location/serial and opening cost.

### Step 8 — Opening Financial Balances
Optional migration of customer receivables, supplier payables, cash/bank and existing checks. Must be handled through controlled opening-balance journals, not ad-hoc edits.

## 2. Category Model
Supports parent-child hierarchy but avoid unnecessary depth. Example:
- Refrigerators
- Washing Machines
- TVs
- Air Conditioners
- Ovens
- Dishwashers
- Water Heaters
- Vacuum Cleaners
- Small Kitchen Appliances

## 3. Brand Model
Brand is independent from category. A brand can sell products across many categories.

## 4. Product Master
Important fields:
- internal SKU;
- barcode(s);
- product/model name Arabic/English;
- brand;
- category;
- manufacturer model number;
- serial tracking policy;
- warranty policy;
- tax code;
- unit of measure;
- purchase/standard cost references;
- cash selling price;
- installment selling price;
- minimum selling price;
- reorder level;
- active/discontinued state;
- specifications JSON or typed attribute values;
- dimensions/weight where useful;
- energy rating;
- country of origin;
- images/attachments.

## 5. Number Sequences
Configurable sequences for:
- sales invoices;
- sales returns/credit notes;
- receipts;
- payment vouchers;
- purchase orders;
- goods receipts;
- supplier invoices;
- supplier returns;
- journal entries;
- installment contracts;
- check deposit batches;
- stock adjustments;
- stock transfers;
- warranty claims.

Sequence generation must be atomic under concurrency.
