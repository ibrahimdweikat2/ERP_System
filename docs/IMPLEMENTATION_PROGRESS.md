# Implementation map and verification record

## Current working instruction — 2026-09-07

The user requested completion of the platform before deciding where tests should run. Continue implementation through the remaining modules without adding or running automated/browser test suites at each task. Prior gate results below remain historical evidence only. New work is recorded as implemented / awaiting user-directed verification, never as a passed financial or production gate. Compilation and migrations are used to keep the application runnable; production activation and restore rehearsal remain separate.

## Repository inspection

The starting workspace contained the 33 numbered specifications, README and a combined reference copy. No application, repository history, migrations or tests existed. All numbered specifications 00–32 were read in order before implementation. The numbered files are the canonical requirements; the combined file is a convenience copy.

## Sequence and gates

Implement phases 0–9 in the order in `21_IMPLEMENTATION_PLAN.md`. Phase 10 is optional and is not silently enabled. A phase is complete only when its documented exit scenario is verified. Unimplemented modules must be visibly unavailable; no fictitious financial dashboard figures.

| Phase | Scope | Status |
| --- | --- | --- |
| 0 | API/UI, sessions, RBAC, audit, sequences, MySQL queue | Passed: 15 backend tests / 56 assertions; 2 browser tests |
| 1 | Setup, fiscal controls, decimal journal engine, tax, currencies, cash/bank | Passed: 27 backend tests / 111 assertions; 3 browser tests |
| 2 | Catalog, movement ledger, serials, transfers/counts/adjustments | Passed: 50-test regression plus opening guard; 5 browser flows plus print regression |
| 3 | Purchasing and supplier payable reconciliation | In progress: supplier invoices/AP/VAT |
| 4 | Customers, POS, sales, receipts, returns | Pending phase 3 gate |
| 5 | Installments, credit, allocation, rescheduling and settlement | Pending phase 4 gate |
| 6 | Checks, deposits, clearing, bounce, replacement | Pending phase 5 gate |
| 7 | Expenses, cashier closing, cash/bank controls | Pending phase 6 gate |
| 8 | Reports, VAT, dashboard and queued exports | Pending phase 7 gate |
| 9 | Security, recovery rehearsal and operational hardening | Pending phase 8 gate |

## Task 0.1 — Runtime and application skeleton

- Objective: establish runnable Laravel API and React/TypeScript/Tailwind application with MySQL 8.
- Files: `backend/`, `frontend/`, `.gitignore`, runtime helper scripts, root README.
- Schema: Laravel users, sessions, password resets, cache, jobs, job batches and failed jobs; MySQL/InnoDB/utf8mb4 only.
- Endpoints/actions: public health endpoint; no business posting yet.
- UI: Arabic RTL authenticated shell, login and structured module navigation.
- Permissions: public health/login; business shell requires active authenticated account.
- Accounting/inventory effects: none.
- Validation: compatible PHP extensions, MySQL version, production build/type checking.
- Manual verification: start backend/frontend; inspect health; open login.
- Acceptance: docs 22 §1 stack, single store, database queue infrastructure; docs 21 phase 0 skeleton.

## Task 0.2 — Identity, permissions and audit

- Objective: first-party Sanctum cookie sessions, login/logout/reset, active-user enforcement and granular RBAC.
- Files: Identity and Audit domains, API controllers/requests/resources, middleware, seeders and feature tests; auth/users/roles/audit screens.
- Schema: roles, permissions, user_roles, role_permissions, append-only audit_logs; user status and login timestamps.
- Endpoints/actions: `/api/v1/auth/*`, `/users`, `/roles`, `/permissions`, `/audit-logs`.
- UI: login, password reset, user and role administration, searchable audit table.
- Permissions: `users.manage`, `roles.manage`, `audit.view`; server checks on every restricted endpoint.
- Accounting/inventory effects: none.
- Validation: unique email, strong passwords, allowed role IDs, active users, last-owner protection; mask secrets in audit.
- Manual verification: owner login; create restricted user; direct forbidden API returns 403; disable user and check prior session is rejected; inspect audit.
- Acceptance: docs 22 §8 unauthorized API rejection and attributed audit; docs 21 phase 0 auth/RBAC/audit.

## Task 0.3 — Sequences and database queue

- Objective: atomic number allocation and observable idempotent MySQL queue processing.
- Files: StoreSetup sequence action/model, Infrastructure queue probe job/command, migrations, worker configs and MySQL tests.
- Schema: unique document sequences and period counters, queue probe runs; purpose-built queue and audit indexes.
- Endpoints/actions: sequence configuration under `settings.manage`; CLI health/probe diagnostics.
- UI: document sequence settings; owner infrastructure health.
- Permissions: `settings.manage`; queue diagnostics restricted to command line/owner.
- Accounting/inventory effects: none; numbers allocate inside the caller's transaction.
- Validation: allowlisted document types/reset policies, unique sequence, safe prefix/padding; concurrent allocations remain unique.
- Manual verification: dispatch probe, run database worker, retry probe, inspect one result; run concurrent sequence regression.
- Acceptance: docs 21 phase 0 exit; docs 22 §1 queue/failed jobs and §8 audit; docs 29 concurrency and queue retry regressions.

## Design decisions

- Arabic operational desk for appliance-store staff: cool paper `#F4F7FA`, ink `#142638`, petrol `#145B69`, steel `#66798A`, line `#DCE5EB`, alert `#A84635`. IBM Plex Sans Arabic headings, Noto Sans Arabic body, tabular system numerals for data. Compact tables with a right-hand navigation rail and a persistent store/status strip; no oversized KPI cards.
- Financial figures and tax rates are never seeded as purported real business data. Effective dates and policy mappings will be configured during setup.
- Local tool runtimes remain workspace-local and ignored. Existing XAMPP services/configuration are left intact.

## Phase 0 verification

- Laravel 12.69.1, Sanctum 4.3.3, PHP 8.2.30; official Laravel 12 support reference: https://laravel.com/framework/docs/12.x/releases.
- MySQL Community Server 8.4.11, isolated port 3307; download/checksum checked against https://dev.mysql.com/downloads/mysql/8.4.html.
- Real MySQL migrations, foreign keys, append-only audit triggers, and 40 numbers allocated by four simultaneous PHP processes passed.
- Database worker processed a real queued probe; deliberate failure reached failed_jobs; replaying the probe did not duplicate its effect.
- Owner browser login, user list, audit log, mobile navigation, logout and rejection of a missing CSRF token passed in Edge/Playwright.
- Frontend production build and TypeScript passed. PHP files formatted with Pint.
- Local API uses port 8188 because 8000 already had another service. UI uses 127.0.0.1:5173.
- MySQL local-only log_bin_trust_function_creators enabled for trigger migrations. Production migrations must use a controlled migration account with the required trigger privileges, separate from the application login.

## Task 1.1 — Store and accounting master configuration

- Objective: persist single-store identity/locale and configurable fiscal, currency, account, journal, tax, cashbox and bank foundations.
- Files: StoreSetup/Accounting/Tax/CashBank models/actions, schema migration and template seeder; setup/master controllers and requests; frontend setup and master-data forms.
- Schema: store_settings singleton, currencies, exchange_rates, fiscal_years, accounting_periods, accounts, journals, tax_codes, account_mappings, cashboxes, bank_accounts.
- Endpoints/actions: store/settings, fiscal-years, accounting-periods, currencies, exchange-rates, accounts, journals, tax-codes, cashboxes, bank-accounts.
- UI: store setup wizard, master-data lists/forms, fiscal periods, chart of accounts.
- Permissions: settings.manage for setup; accounting.view for accounting reads; accounting.period_lock for period state changes; cashbank.manage for treasury masters.
- Accounting/inventory effects: no posting from master creation; chart template supplies mappings without opening balances.
- Validation: one store/base currency; non-overlapping fiscal years/periods; positive decimal currency rates; proper account types; no hard-coded VAT rate; effective tax dates; immutable rate records.
- Manual verification: save identity; choose fiscal dates; inspect generated periods/chart; configure tax and bank; reopen the setup screen to verify persistence.
- Acceptance: docs 03 first-use foundation, docs 05 configuration/period rules, phase 1 setup/master requirements.

## Task 1.2 — Exact journal posting and reversal

- Objective: post balanced journals synchronously and reverse by a new entry while preserving original lines.
- Files: Accounting posting/reversal actions and resources, Decimal support, journal endpoints/UI, MySQL journal/period regression tests.
- Schema: journal_entries, journal_lines, idempotency_keys; immutable posted-entry/line triggers, reversal uniqueness and journal indexes.
- Endpoints/actions: journal-entries drafts, post, reverse; accounting-period lock/reopen with reason.
- UI: journal editor/detail/post/reverse dialogs and print layout.
- Permissions: accounting.journal_create, accounting.post, accounting.reverse, accounting.period_lock; control accounts reject normal manual posting.
- Accounting/inventory effects: balanced GL entries only; reversal creates opposite lines in an open period; no stock or operational subledger edits.
- Validation: positive single-sided decimal lines, at least two lines, exact debit=credit, active accounts, open period, frozen currency/rate/base amounts, immutable posted documents, duplicate submission protection.
- Manual verification: post cash/capital journal; retry posting; reverse in open period; attempt unbalanced/control-account/locked-period posting and inspect rejection/audit.
- Acceptance: phase 1 exit; docs 22 §2 exact balance, immutability, reversal history and locks; docs 26 financial/currency invariants.

## Phase 1 verification and policy decisions

- Real MySQL tests verify exact four-decimal journals, rollback on imbalance, manual control-account rejection, locked periods, later-period reversal, immutable ledger triggers, frozen foreign-currency amounts/rates, idempotent HTTP posting and payload conflicts.
- Four simultaneous PHP posting processes returned one permanent entry number; exactly one journal, two lines and one posting audit remained.
- Browser test database is separate (`erp_browser_test` on API 8199/UI 5199). Setup, fiscal-year creation, a 1000.0001 journal, post, reversal and final period lock passed through the UI.
- Posted original journals retain status `posted`; reversal is derived from a linked, unique reversal entry. They are never updated merely to label them reversed.
- Fiscal-year creation generates monthly periods, including partial first/last months. Soft closure can reopen with permission/reason; final lock cannot reopen. Corrections use a later open period.
- Manual entries use the general journal. Operational journals can only be posted through domain actions. Reversing operational ledger entries directly is rejected to preserve subledger integrity.
- Account mappings are configurable before use; remapping used accounts requires a separate controlled financial migration rather than silently moving existing balances.
- Currency rates are immutable. Foreign-currency manual drafts use the most recent rate on/before entry date. Posting freezes it; a reversal preserves original base amounts/rate.
- Backend tests and frontend build/lint passed; PHP formatting checked. Coverage percentage has not been measured.

## Task 2.1 — Catalog and internal locations

- Objective: manage appliance catalog, category hierarchy, brands, units, warranty policies, pricing, barcodes and internal stock locations.
- Files: Catalog domain models/actions/resources, catalog migration/seeders/controllers; product/category/brand/warranty/location screens and regression tests.
- Schema: categories, brands, units, warranty_policies, products, product_barcodes and stock_locations; decimal prices/quantities, unique SKU/barcodes, purposeful FK/indexes.
- Endpoints/actions: categories, brands, units, warranty-policies, products and stock-locations; products expose no editable quantity field.
- UI: catalog list/editor, category/brand/warranty/location forms, serial-tracking flag and decimal price fields.
- Permissions: catalog.view/catalog.manage; inventory.view for locations; cost fields require inventory.view_cost or sales.view_cost on the API.
- Accounting/inventory effects: none; master-data edits never modify stock balances.
- Validation: unique normalized SKU/barcodes, acyclic categories, nonnegative string decimals, valid active master references and warranties; product tracking cannot change after stock history.
- Manual verification: create category/brand/warranty/product; search SKU/barcode; edit price; test restricted-user cost visibility; confirm quantity is unavailable for editing.
- Acceptance: phase 2 catalog/master items, docs 03 product master, docs 08 single-store internal locations, docs 22 §1/§8.

## Task 2.2 — Movement ledger and serialized stock

- Task 2.1 gate passed: five MySQL catalog tests (44 assertions), product/brand browser flow with barcode search and persisted specifications, TypeScript production build and PHP formatting. Inventory fields remain excluded from product writes; API cost visibility is permission-tested.
- Runtime correction (2026-09-07): the local environment had been changed to MariaDB. The user explicitly authorized MySQL 8 adoption with preservation of current ERP data. Migrated 320 rows across 41 data tables to `erp_store_mysql8` on MySQL 8.4.11/3307; compared every row with JSON-aware canonical hashes and checked 83 foreign keys. Laravel migration metadata remains destination-specific. Original MariaDB database is untouched; original environment, schema/data JSON, SHA-256 manifest and verification report are under ignored `.runtime/migration-backups`. The application key and existing users/sessions were preserved. Application health returned OK after switching.

- Objective: receive opening stock, transfer internal stock and post approved adjustments/count variances with exact value reconciliation.
- Files: Inventory/Approvals models and actions, system journal action, migrations, inventory screens and MySQL scenario/concurrency tests.
- Schema: balances/movements, serials/history, transfers/lines, adjustments/lines, counts/lines and approval payload records.
- Endpoints/actions: inventory availability/movements/serial history; stock transfers, adjustments/counts, approval and post.
- UI: stock overview, serial history, transfer/adjustment/count forms with exact serial selectors and approval dialogs.
- Permissions: inventory.view/receive/transfer/adjust/count; approvals.decide; inventory.view_cost for cost fields.
- Accounting/inventory effects: immutable movement rows maintain balances; opening/adjustments post balanced inventory journals; internal transfers preserve total inventory value.
- Validation: no negative stock, exact integer serial counts, normalized unique serials, source row locks, approved payload integrity, stale-count rejection, immutable posted records.
- Manual verification: opening receive -> transfer -> count/adjust; reconcile quantity/value to movements/GL; retry post; attempt duplicate serial and negative stock.
- Acceptance: phase 2 exit and inventory invariants; later purchasing/sales phases stay pending until this gate passes.

## Phase 2 verification and policy decisions

- Full regression before print additions: 50 MySQL backend tests / 267 assertions and five browser scenarios passed. Added an opening-after-operational-movement regression (five assertions) and verified the transfer destination API. Inventory tests cover balances/GL, exact serial identity and history, approval changes, locked periods, immutable records, cost permissions and full rollback when accounting fails.
- Two simultaneous transfers of the same stock were tested with both serial and quantity tracking: one posted and the other was rejected; one permanent number/audit remained and inventory value was preserved.
- Moving weighted average is maintained per product/internal location, four decimals for total value and eight for average cost. A full issue consumes the exact remaining value. Transfers move the same value; they do not post GL. Serial acquisition cost remains available separately for traceability.
- Adjustment approvals compare the gross absolute movement value, including both missing and additional serials even when their net count is zero. Default threshold zero requires review; opening always requires approval. Policy version and the exact document/cost payload are checked on approval and posting. Self-approval can be disabled in settings.
- Counts preserve the original quantity/serial snapshot and reject posting after intervening movement. Corrections use new documents; posted stock records and histories are immutable. Backdating before later stock movements is rejected. Every stock posting, including a transfer, requires an open accounting period.
- Zero-value movements remain legitimate quantity/history events and create no zero-value GL entry. Inventory value and total movement value must reconcile exactly to the inventory control account.
- RTL print preview covers transfers, adjustments and counts with business identity/tax fields, permanent number/date, locations, serials, status and permission-controlled values. Browser print output hides application navigation and controls and can be saved as A4 PDF. No configured business logo exists yet.
- Build/type checking/lint and PHP formatting passed. Coverage percentage is still unmeasured. Browser fixtures are isolated from the migrated live database; README now documents the active database and dedicated browser test workflow.
- Final inventory browser rerun passed in 28 seconds including A4 PDF output. The first print run passed printing but timed out while the balance page was still loading alongside database tests; the browser assertion timeout now allows 15 seconds and defaults to isolated port 5199.

## Task 3.1 — Supplier master

- Objective: maintain supplier identities, contact people, payment terms, default currency and protected payment details before procurement.
- Files: Purchasing supplier model/action, migration, request/resource/controller, API routes, supplier list/editor and regression tests.
- Schema: suppliers with unique normalized code, names/tax/address, contact JSON, currency FK, decimal credit limit, payment terms, encrypted bank JSON, active/version and attributed changes.
- Endpoints/actions: GET/POST suppliers, GET/PUT suppliers/{id}, purchasing currency options; idempotent create and version-checked update.
- UI: searchable Arabic supplier list, details and form with repeatable contact people; bank fields restricted to payment permission.
- Permissions: purchasing.view for reads, purchasing.create for master changes, purchasing.pay to read/change bank details. Unauthorized fields are rejected by the API.
- Accounting/inventory effects: none. No editable opening balance or stock fields; future AP opening balances will use controlled journal/subledger documents.
- Validation: code uniqueness/normalization, exact string credit limit, active currency, valid contact emails and bounded fields, stale edit rejection; banking values encrypted and masked in audit.
- Manual verification: create/edit/search supplier, verify contacts/currency persist, reject duplicate code and stale version; restricted procurement user cannot view/change bank details but can edit other fields without clearing them.
- Acceptance: docs 09 supplier master and docs 15/17/22 server authorization, sensitive-data protection and audit.

- Task 3.1 gate passed: four MySQL tests / 55 assertions and supplier browser creation/edit/contact/bank persistence scenario passed. Production build/lint and supplier routes/migrations passed. Supplier bank details are model-hidden, encrypted at rest, omitted from audit payloads and preserved when procurement users edit ordinary fields. No operational supplier data was seeded.

## Task 3.2 — Purchase orders and approvals

- Objective: prepare, approve and issue purchase orders with exact totals and frozen supplier/currency/tax details for later receipt matching.
- Files: Purchasing purchase-order models/actions, tax calculation and currency snapshot helpers, migration, request/resource/controller, PO list/editor/detail/print and tests.
- Schema: purchase_orders/lines, purchase_order approval policy; immutable issued header/lines, linked approval/version/hash, permanent number, supplier identity and rate snapshots.
- Endpoints/actions: purchase-orders list/create/show/edit/submit/issue; dedicated approval decision under purchasing.approve; policy settings with reason; purchasing product/tax options.
- UI: supplier/order lines, quantity/pricing/discount/tax mode, totals/status and approval flow; Arabic PO print view.
- Permissions: purchasing.view/create/approve; settings.manage for threshold and task-separation policy. Purchasing document prices are visible to purchasing.view; inventory valuation remains separately protected.
- Accounting/inventory effects: none until goods receipt. PO quantities never change balances or AP.
- Validation: active supplier/currency/products, quantity precision, decimal-string amounts, discounts within line value, effective tax code, frozen FX snapshot, optimistic versions and approval payload revalidation. Editing an approved draft requires fresh approval; issued orders cannot be edited.
- Manual verification: prepare PO, request/approve/issue, print, repeat issue; change draft after approval and confirm invalidation; stale version/permission/inactive masters/invalid discount rejected.
- Acceptance: docs 09 PO and high-value approval; docs 15 exact approval payload; docs 31 printable PO; matching foundation for phase 3 receipts/invoices.

- Five dedicated MySQL PO tests / 77 assertions passed: inclusive-tax rounding, discounted totals, fixed FX/rate provenance, supplier identity snapshot, approvals, separate reviewer, changed policy, stale draft, immutable issued records and no stock/GL effect. The browser create -> approve -> issue -> print scenario passed.
- Approval threshold compares total including quoted tax converted to base currency at the saved rate; zero defaults to review all POs. Editing a draft snapshots the currently configured rate again and invalidates approval. Null PO tax explicitly means tax is not applied to the quotation, not an inferred tax exemption or an input-VAT posting.
- Document counters now use a current locking read after acquiring their sequence lock, protecting distinct concurrent documents whose earlier reads created an older MySQL repeatable-read snapshot. Added a four-process PO issuance regression. Broader backend regression is running before the goods-receipt task begins.

- Task 3.2 gate passed: full backend suite 61 tests / 412 assertions (3m27s), including four distinct concurrent PO numbers; final PO browser issue/print rerun passed, including A4-width overflow assertion. Frontend build/lint and PHP formatting passed.

## Task 3.3 — Goods receipt and PO quantity matching

- Objective: record actual deliveries and device condition, match issued POs, and recognize received inventory against GRNI atomically.
- Files: Purchasing goods-receipt models/actions/migration/request/resource/controller, receipt editor/detail/print, PO received-quantity projections, seeding and tests.
- Schema: goods_receipts/lines with immutable posted state, supplier/rate/product snapshots, optional PO/line references, condition/location/serials, exact foreign/base cost and linked movement/journal.
- Endpoints/actions: goods-receipts list/create/show/edit/post; purchasing receiving location options and remaining PO quantities.
- UI: select issued PO or authorized direct receipt; actual date/delivery reference, per-line condition/location/quantity/serial capture, preview and post/print.
- Permissions: purchasing.view/receive; new purchasing.receive_without_po for direct delivery costing (owner by default). Server repeats permission checks on direct create/edit/post.
- Accounting/inventory effects: Dr inventory / Cr GRNI at frozen receipt-date FX; ledger/serials/balances and GL in one transaction. No supplier AP or VAT posting until invoice matching.
- Validation: issued matching PO/supplier/currency, no over-receipt under locks, exact normalized serial count, no duplicate serials, valid unit precision, damaged/open-box devices in a non-sellable inspection/damaged location, open period and no stock backdating.
- Cost policy: PO receipt uses its approved net line price after discount and before quoted VAT. Partial receipts allocate the net foreign line value proportionally, with final remainder consumed exactly; receipt-date FX fixes base cost. Direct receipts require an explicit foreign unit cost and permission. Invoice price/FX variance handling is deferred to task 3.4 and cannot silently change received inventory values.
- Manual verification: receive two partial deliveries including damaged device quarantine; reject excess/duplicate serial, retry post, reconcile inventory/GRNI and serial history, print delivery.
- Acceptance: docs 09 goods receipt/2-3 way matching and optional direct path, docs 05 perpetual inventory/FX, docs 22 reconciliation/atomic posting, docs 31 printable receipt.

- Eight targeted receipt/concurrency tests passed (55 assertions before adding deletion-audit assertions): partial PO matching, cumulative tiny-value rounding, quarantine, duplicate serial and over-receipt rejection, direct-receipt permissions, repeat posting, locked-period/account failure rollback, immutable posted lines and original foreign amounts/rates on the GL.
- Two receipt processes in different accounting periods competed for the same remaining PO quantity: one posted and one was rejected; exactly one movement, serial and journal remained. This verifies the PO lock independently of the period lock.
- Browser receipt scenario passed: two lines from one PO, healthy and damaged device separation, exact 200.0002 total, post, zero remaining PO quantity, correct damaged serial state and A4 PDF output without horizontal overflow. Issued/posted purchase document edit/delete attempts now also record rejection audits.
- Receipt FX is saved with the draft and refreshed on deliberate edit; posting preserves it and writes explicit original foreign amounts plus the same frozen rate to the system journal. The GL uses the sum of posted line base values, including their stored rounding, so it matches inventory exactly.

- The 69-test broader run exposed a remaining counter race: Laravel's `updateOrInsert` checks existence through a non-locking read even after a current locking read succeeded. A deterministic two-connection regression reproduced the duplicate key after establishing an older repeatable-read snapshot. The allocator now explicitly updates or inserts using the locked row state, and PO worker errors return a nonzero exit code. Full regression is being repeated before closing this task.
- Running the browser suite concurrently with schema rebuilding caused login/save timeouts in two scenarios; six scenarios passed. Browser verification will run independently to avoid competing database I/O. These timeouts are recorded as failed checks until the isolated rerun passes.
- Review of test setup found the database-name guard ran after Laravel's migration traits. It now validates during application creation, before those hooks. A standalone subprocess probe rejects an invalid database name before a substituted migration hook (1 test / 3 assertions).
- The slower full rerun was stopped during repeated schema rebuilding after its completed cases passed. Process tests now use Laravel's database truncation isolation: committed fixtures and real locks/triggers remain, while populated test tables are emptied between scenarios. Approval defaults are restored through idempotent seeding; the next test class rebuilds schema once. A fresh full run will verify this change and the numbering fix together.
- Disk diagnostics identified sustained E: I/O pressure (disk queue 6, about 40 MB/s) while C: was idle. Provisioned a separate local MySQL 8.4 test instance on port 3308 with disposable data under `%LOCALAPPDATA%/ERP-MySQL-Tests/data`; active store remains on port 3307 and was not moved or reset. Test environment files and startup helpers now use 3308. No production durability setting was changed.
- Full backend gate passed on the isolated test instance: 71 tests / 477 assertions, 1m48.895s. Deterministic old-snapshot numbering, distinct concurrent POs, cross-period receipt competition, database guard and all existing accounting/inventory regressions passed. PHP formatting, final frontend build/lint also passed. Browser rerun is next.

- Task 3.3 gate passed: full backend 71 tests / 477 assertions, followed by the Arabic throttle regression (1 test / 9 assertions), and all 8 browser scenarios in 1.5m. Fast browser runs had correctly hit the five-logins-per-account limit; isolated per-scenario owner accounts now exercise each real login without changing production limits. The 429 message is localized and Retry-After preserved.

## Task 3.4 — Supplier invoice matching, AP, input VAT and private attachments

- Objective: capture supplier invoices against posted receipts, recognize exact supplier liabilities/input tax and preserve the source scan with authorized access.
- Files: Purchasing invoice models/actions/policy and matching calculation; Tax transaction and supplier ledger models; private attachment action/controller; migrations, API requests/resources/routes, invoice editor/detail/print/policy/attachment UI and tests.
- Schema: supplier_invoices/lines, purchasing_invoice_policy, immutable supplier_ledger_entries and tax_transactions, private document_attachments. Invoice uniqueness is per supplier and normalized supplier invoice number. Posted headers/lines/register entries are immutable.
- Endpoints/actions: supplier-invoices list/create/show/edit/post, matching receipt options/remaining quantities, invoice policy, invoice attachment upload/list/download and initial supplier statement drilldown.
- UI: supplier invoice number, actual invoice date, posting date, due date, supplier/currency, receipt lines, price/discount/effective tax/recoverability, variance preview/reason, private scan and posted journal link.
- Permissions: purchasing.view for source documents and attachments; purchasing.invoice for draft edits, attachments and posting; settings.manage for policy and account mappings. Files never have public paths/URLs.
- Accounting/inventory effects: Dr GRNI at allocated historical receipt value; Dr input VAT for eligible configured treatment; signed purchase-price/FX variance; Cr AP at invoice value. AP subledger and tax register post in the same transaction as GL. Receipt quantities/values do not change when the invoice posts.
- Matching policy: same supplier and currency, only posted receipts, locked receipt headers and current reads to prevent billing the same quantity twice. Partial matching uses cumulative allocation and exact final remainder for receipt foreign/base values. Actual invoice date controls effective tax; posting date controls the open period and saved FX snapshot. Saved snapshots remain fixed on posting; explicit draft edit refreshes them.
- Configurable ambiguity: price variance defaults to blocked; an explicit policy can permit posting to a selected expense mapping with a required reason. Non-recoverable tax defaults to blocked pending an explicit expense policy/mapping. Recoverable VAT requires a VAT-registered store and explicit line eligibility. No statutory percentage or automatic tax claim is assumed. All policy changes are versioned/audited and rechecked on posting.
- Validation: invoice date <= posting date <= today, posting date >= receipts, due date >= invoice date; exact bounded decimal strings, active masters, valid effective tax code, explicit tax classification, no excess matched quantity, duplicate number rejection, required attachment if configured, optimistic version and atomic rollback on account/period failure.
- Attachment rules: private local disk, allowlisted PDF/JPEG/PNG up to 10 MB, MIME/content validation, random storage key, checksum/size/name/uploader/parent metadata, authenticated parent authorization on download, no deletion of retained source documents. Upload retries deduplicate by invoice/checksum under the parent lock.
- Manual verification: partial receipt matching, inclusive VAT, foreign FX and price variance review, duplicate/overbilling rejection, private PDF upload/download, required attachment policy, post/retry/print and AP/GRNI/VAT reconciliation.
- Acceptance: docs 09 invoice/AP/2-3 way matching and variance/attachment controls, docs 05 original FX and immutable input VAT, docs 22 atomic/reconciled posting, docs 31 private source attachments.
