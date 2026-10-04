# Graph Report - .  (2026-10-01)

## Corpus Check
- 432 files · ~289,972 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 2758 nodes · 7942 edges · 170 communities (140 shown, 30 thin omitted)
- Extraction: 91% EXTRACTED · 9% INFERRED · 0% AMBIGUOUS · INFERRED: 683 edges (avg confidence: 0.85)
- Token cost: 533,113 input · 0 output

## Community Hubs (Navigation)
- Operations Frontend Pages
- Test Fixtures & Seed Models
- Purchasing & Accounting Actions
- Accounting & Master Data UI
- Core API Controllers
- Frontend API Client & Settings
- Feature Test Harness
- Sales, Installments & Credit
- Inventory Stock Ledger
- Demo Seeding & Check Commands
- Auth & App Shell UI
- Purchasing UI & DataTable
- Idempotent Request Controllers
- Catalog & Purchasing Models
- Inventory Relations & Audit Models
- Auth & Reporting Controllers
- Database Design Spec
- Supplier Invoices & Attachments
- Stock Documents & Fiscal Models
- Installments & Checks Spec
- Queued Jobs & Backups
- Architecture & API Spec
- Product Images & Branding
- Inventory Phase 2 Progress
- Inventory Frontend Pages
- Routes & Base Controller
- Plan, Invariants & Testing
- Accounting & VAT Spec
- All-in-One Master Doc
- Journal & Audit API
- Installment Credit Policy Spec
- Inventory Feature Tests
- Store Setup Phase 1
- Tech Stack & Foundation
- Journal Posting Tests
- Purchase Order & Receipt Tests
- Acceptance & Deployment Rules
- Accounting Examples & Go-Live
- Frontend App TSConfig
- HTTP Middleware & Bootstrap
- Product API
- ERD & Posting Flow
- Phase 3 Purchasing Tasks
- Form Request Validation
- Goods Receipt API
- Stock Document API
- Backend NPM Package
- Accounting Setup API
- Frontend Node TSConfig
- Frontend Runtime Dependencies
- Inventory Performance & Invariants
- Master Index & UX
- ERD & State Machines
- Permissions, Approvals & Audit
- Supplier Invoice Tests
- Product Requirements & Roles
- Phase 0 Foundation
- Frontend Dev Tooling
- Supplier API
- Inventory & Serials Spec
- Store Setup Wizard Spec
- Reporting & Dashboards Spec
- Security, Audit & Backup Spec
- Purchase Order Workflow
- Check Management Spec
- Legal & VAT References
- Journal Posting & Idempotency
- Conventions & Notifications
- Composer Scripts
- Sales, POS & Returns Spec
- Supplier Invoice Save Action
- Purchase Order API
- Azure Deployment Stack
- Cash & Bank Treasury
- Identity Feature Tests
- Docker Compose Services
- Purchasing & Suppliers Spec
- Frontend RTL Entry & Tooling
- Customer Payments API
- Daftar ERP Overview
- AWS & Docker Deploy Guides
- Frontend Package Scripts
- Business Approvals & Audit
- Service Providers
- Catalog Tests
- Login MFA Tests
- Supplier Tests
- Demo Year Data
- Oxlint Config
- Playwright E2E Specs
- Document Sequences
- Composer Metadata
- MySQL & Immutable Journals
- Concurrency Worker Tests
- Composer Config
- Users & Jobs Migrations
- Cache & Settlement Migrations
- Accounting & Inventory Migrations
- Docker Volumes & Backup
- Overdue Aging & Scheduler
- VAT Reporting & Exports
- Composer Runtime Requires
- Composer Dev Requires
- Sales & Returns Migration
- Local Dev & Data Import
- AWS Production Config
- PSR-4 Autoload
- Logging Config
- User Factory
- Cash Transfer Action
- Expense Action
- Test Autoload
- Laravel Package Discovery
- Composer Keywords
- AWS Deploy Script
- Docker PHP Entrypoint
- Frontend Root TSConfig
- Artisan Entry Point
- AWS Install Script
- Azure Server Setup
- QR Code Package
- Tailwind Vite Plugin
- Node Types Package
- Vite Package
- Foundation E2E Spec
- Decimal Money Rule
- Performance Anti-Patterns

## God Nodes (most connected - your core abstractions)
1. `User` - 115 edges
2. `api()` - 107 edges
3. `StoreSetting` - 100 edges
4. `useAuth()` - 87 edges
5. `BusinessException` - 85 edges
6. `Product` - 83 edges
7. `RecordAudit` - 74 edges
8. `Controller` - 73 edges
9. `Role` - 70 edges
10. `IdempotentRequest` - 68 edges

## Surprising Connections (you probably didn't know these)
- `LAN access configuration (APP_URL, SANCTUM_STATEFUL_DOMAINS, firewall rule)` --conceptually_related_to--> `Same-origin SPA serving with /api and /sanctum proxy`  [INFERRED]
  docker/README.md → README.md
- `supervisor-daftar.conf (queue, maintenance, scheduler)` --semantically_similar_to--> `Supervisor configuration (deploy/supervisor.conf)`  [INFERRED] [semantically similar]
  deploy/aws/README.md → README.md
- `nginx-daftar.conf` --semantically_similar_to--> `web service (Nginx, daftar-web image)`  [INFERRED] [semantically similar]
  deploy/aws/README.md → docker-compose.yml
- `deploy/aws/export-data.ps1` --semantically_similar_to--> `scripts/docker-import-local-data.ps1`  [INFERRED] [semantically similar]
  deploy/aws/README.md → docker/README.md
- `Production backend/.env settings (APP_ENV, SANCTUM_STATEFUL_DOMAINS, SESSION_SECURE_COOKIE)` --semantically_similar_to--> `LAN access configuration (APP_URL, SANCTUM_STATEFUL_DOMAINS, firewall rule)`  [INFERRED] [semantically similar]
  deploy/aws/README.md → docker/README.md

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **Financial Posting Transaction Flow** — docs_02_technical_architecture_posting_action_protocol, docs_02_technical_architecture_postsalesinvoiceaction, docs_04_database_design_concurrency_locks, docs_04_database_design_inventory_movements, docs_04_database_design_journal_entries, docs_04_database_design_audit_logs, docs_02_technical_architecture_transaction_boundaries [INFERRED 0.85]
- **Installment Sale Lifecycle (credit check, contract, down payment, schedule, allocation)** — docs_10_sales_pos_and_returns_installment_sale, docs_11_customers_and_credit_control_credit_decision_rule_engine, docs_06_installment_engine_installment_contract, docs_06_installment_engine_down_payment, docs_04_database_design_installment_schedule, docs_06_installment_engine_payment_allocation [INFERRED 0.85]
- **Append-Only / Never-Overwrite History Pattern** — docs_00_master_index_no_silent_deletion, docs_05_accounting_and_palestinian_vat_posted_document_integrity, docs_08_inventory_serials_warranty_perpetual_stock_movement_ledger, docs_06_installment_engine_rescheduling, docs_07_check_management_bounced_check, docs_04_database_design_check_status_history, docs_11_customers_and_credit_control_customer_merge [INFERRED 0.75]
- **Post-Dated Check Lifecycle Accounting Flow** — docs_23_accounting_examples_post_dated_check_received, docs_23_accounting_examples_check_deposited, docs_23_accounting_examples_check_cleared, docs_23_accounting_examples_check_bounced, docs_27_erd_and_state_diagrams_check_state, docs_21_implementation_plan_phase_6_checks [INFERRED 0.85]
- **Financial Reconciliation Assurance Chain** — docs_23_accounting_examples_reconciliation_principle, docs_29_testing_and_quality_strategy_reconciliation_tests, docs_26_business_rules_and_invariants_financial_invariants, docs_22_acceptance_criteria_accounting_criteria, docs_32_glossary_control_account [INFERRED 0.85]
- **MySQL Database Queue Runtime (no Redis)** — docs_18_performance_mysql_and_queue_database_queue, docs_18_performance_mysql_and_queue_worker_segmentation, docs_20_deployment_no_docker_supervisor_workers, docs_24_codex_implementation_prompt_queue_design, docs_19_notifications_and_scheduler_notifications_queue, docs_16_reporting_and_dashboards_export_strategy [INFERRED 0.85]
- **Domain Posting Actions (transactional posting pattern)** — docs_erp_master_documentation_all_in_one_post_sales_invoice_action, docs_erp_master_documentation_all_in_one_post_purchase_invoice_action, docs_erp_master_documentation_all_in_one_receive_customer_payment_action, docs_erp_master_documentation_all_in_one_clear_check_action, docs_erp_master_documentation_all_in_one_bounce_check_action, docs_erp_master_documentation_all_in_one_post_inventory_adjustment_action, docs_erp_master_documentation_all_in_one_posting_architecture [EXTRACTED 1.00]
- **Check Settlement Lifecycle (state, accounting, history, deposit)** — docs_erp_master_documentation_all_in_one_check_lifecycle_state_machine, docs_erp_master_documentation_all_in_one_check_accounting_lifecycle, docs_erp_master_documentation_all_in_one_check_status_history_table, docs_erp_master_documentation_all_in_one_check_deposit_batches_table, docs_erp_master_documentation_all_in_one_bounced_check_handling, docs_erp_master_documentation_all_in_one_pma_ecc [INFERRED 0.85]
- **Phase 3 Purchasing Chain (PO -> GR/GRNI -> Supplier Invoice/AP)** — docs_implementation_progress_task_3_2_purchase_orders_approvals, docs_implementation_progress_task_3_3_goods_receipt_po_matching, docs_implementation_progress_grni_account, docs_implementation_progress_task_3_4_supplier_invoice_ap_vat, docs_implementation_progress_supplier_ledger_entries, docs_erp_master_documentation_all_in_one_procurement_flow [INFERRED 0.85]
- **Laravel services sharing the x-laravel template (daftar-api image, erp-storage, db dependency)** — docker_compose_x_laravel, docker_compose_app, docker_compose_queue, docker_compose_maintenance, docker_compose_scheduler [EXTRACTED 1.00]
- **Trigger-protected posted documents and their operational consequences** — readme_immutable_posted_journals, docker_compose_log_bin_trust_function_creators, deploy_azure_docker_compose_db, demo_data_readme_erp_demo_year_command, readme_least_privilege_db_credentials [INFERRED 0.85]
- **Daftar deployment topologies (local dev, Docker, Azure VM, AWS EC2)** — readme_local_dev_topology, docker_compose, deploy_azure_docker_compose, deploy_aws_readme_ec2_native_deployment [INFERRED 0.85]

## Communities (170 total, 30 thin omitted)

### Community 0 - "Operations Frontend Pages"
Cohesion: 0.05
Nodes (123): DeleteDraftButton(), CheckFollowup(), FeeForm(), FollowupForm(), Batch, BatchDetail(), Check, CheckDepositsPage() (+115 more)

### Community 1 - "Test Fixtures & Seed Models"
Cohesion: 0.05
Nodes (39): CreateFiscalYear, SaveManualJournal, Account, Journal, DecideInventoryApproval, SaveProduct, Unit, Permission (+31 more)

### Community 2 - "Purchasing & Accounting Actions"
Cohesion: 0.07
Nodes (19): PostSystemJournal, AccountingPeriod, OwnerAutoApproval, RecordAudit, ReceiveInstallmentChecks, ReceiveCustomerPayment, DeleteSupplier, PostSupplierInvoice (+11 more)

### Community 3 - "Accounting & Master Data UI"
Cohesion: 0.06
Nodes (68): MoneyInput(), DeleteDialog(), DeleteIconButton(), Badge(), Empty(), ErrorNotice(), Field(), Loading() (+60 more)

### Community 4 - "Core API Controllers"
Cohesion: 0.06
Nodes (9): CatalogMasterController, CustomerController, FoundationController, InstallmentController, InventoryController, MfaController, OperationsController, RoleController (+1 more)

### Community 5 - "Frontend API Client & Settings"
Cohesion: 0.06
Nodes (49): #root mount point loading /src/main.tsx, App(), client, Permit(), RequireAuth(), Pagination(), ApprovalDialog(), MasterEditor() (+41 more)

### Community 6 - "Feature Test Harness"
Cohesion: 0.06
Nodes (13): Totp, AccountingSetupTest, FoundationSequenceTest, OpeningStockTest, SupplierDeleteTest, TestCase, Illuminate\Foundation\Testing\RefreshDatabase, Illuminate\Foundation\Testing\TestCase (+5 more)

### Community 7 - "Sales, Installments & Credit"
Cohesion: 0.06
Nodes (15): CustomerLedger, DeleteCustomer, SettleCustomer, Customer, AllocateInstallments, CreditDecision, ManageInstallmentContract, PaySupplier (+7 more)

### Community 8 - "Inventory Stock Ledger"
Cohesion: 0.06
Nodes (18): App\Domains\Inventory\Enums\StockDocumentType, Product, StockDocumentType, PostStockDocument, StockDocumentType, PreviewStockDocument, StockDocumentType, StockLedger (+10 more)

### Community 9 - "Demo Seeding & Check Commands"
Cohesion: 0.07
Nodes (10): BackupStore, SeedDemoYear, ManageCheck, CheckSettlementTest, InstallmentChecksTest, TestResponse, DatabaseGuardTest, Illuminate\Console\Command (+2 more)

### Community 10 - "Auth & App Shell UI"
Cohesion: 0.07
Nodes (36): AuthProvider(), NavGroup, navigation, NavItem, ErpShell(), icons, OtpInput(), OtpStatus (+28 more)

### Community 11 - "Purchasing UI & DataTable"
Cohesion: 0.08
Nodes (36): Column, DataTable(), Filters(), PrintDialog(), PrintView(), Health, Store, Attachment (+28 more)

### Community 12 - "Idempotent Request Controllers"
Cohesion: 0.10
Nodes (7): GenerateSchedule, CheckController, TreasuryController, WarrantyController, OperationRules, IdempotentRequest, Illuminate\Validation\Rule

### Community 13 - "Catalog & Purchasing Models"
Cohesion: 0.05
Nodes (12): Brand, Category, ProductBarcode, WarrantyPolicy, PaymentAllocation, PurchaseOrderLine, SupplierCreditNoteLine, SupplierInvoiceLine (+4 more)

### Community 14 - "Inventory Relations & Audit Models"
Cohesion: 0.05
Nodes (10): JournalLine, AuditLog, InventoryBalance, InventoryMovement, StockAdjustmentLine, StockCountLine, StockTransferLine, PrepareSupplierInvoiceLines (+2 more)

### Community 15 - "Auth & Reporting Controllers"
Cohesion: 0.10
Nodes (9): AuthController, CustomerSettlementController, StreamedResponse, ReportController, SalesController, SupplierSettlementController, UserResource, Illuminate\Http\Request (+1 more)

### Community 16 - "Database Design Spec"
Cohesion: 0.12
Nodes (41): Principle: Movement-Ledger Based Inventory, accounting_periods table (open|soft_closed|locked), accounts table (Chart of Accounts), bank_accounts table, cashboxes table, check_allocations table, check_deposit_batches / check_deposit_batch_items, check_return_reasons table (+33 more)

### Community 17 - "Supplier Invoices & Attachments"
Cohesion: 0.08
Nodes (11): AttachSupplierInvoice, DocumentAttachment, SupplierBalances, SupplierInvoice, DocumentController, StreamedResponse, InvoiceAttachmentController, SupplierInvoiceController (+3 more)

### Community 18 - "Stock Documents & Fiscal Models"
Cohesion: 0.07
Nodes (9): FiscalYear, fromRoute(), InventoryDocument, StockAdjustment, StockCount, StockTransfer, SupplierCreditNote, SupplierPayment (+1 more)

### Community 19 - "Installments & Checks Spec"
Cohesion: 0.08
Nodes (35): Accessibility (no color-only status), BounceCheckAction, Bounced Check Handling and Replacement, Check as Financial Instrument with Lifecycle, Check Center, check_deposit_batches / check_deposit_batch_items, Check Invariants, Check Lifecycle State Machine (+27 more)

### Community 20 - "Queued Jobs & Backups"
Cohesion: 0.11
Nodes (15): QueueProbe, RefreshOperationalAlerts, ReconcileLedgers, ReportEngine, CreateBackup, GenerateReportExport, FailingJob, Illuminate\Bus\Queueable (+7 more)

### Community 21 - "Architecture & API Spec"
Cohesion: 0.11
Nodes (32): Explicitly Excluded Tech/Scope (Docker, Redis, PostgreSQL, multi-branch), Mandatory Technology Stack (Laravel, MySQL 8, React/TS/Tailwind), Decimal Monetary Values (never floating point), Non-Functional Requirements, Out of Scope (storefront, multi-branch, MRP, Docker, Redis/PostgreSQL), Backend Stack (PHP/Laravel, REST JSON, MySQL 8), Laravel Database Queue without Redis (named queues), Domain Modules (app/Domains/*) (+24 more)

### Community 22 - "Product Images & Branding"
Cohesion: 0.12
Nodes (7): DeleteProduct, SaveProductImage, ProductImage, ProductImageController, UploadedFile, ProductImageTest, StoreBrandingTest

### Community 23 - "Inventory Phase 2 Progress"
Cohesion: 0.09
Nodes (31): Brand Model (independent of category), Category Model (parent-child hierarchy), Exchange as Return + New Sale, inventory_balances (maintained summary), inventory_movements (immutable movement ledger), Landed Cost, Movement-Ledger Based Inventory, Moving Weighted Average Costing (+23 more)

### Community 24 - "Inventory Frontend Pages"
Cohesion: 0.13
Nodes (25): SerialOption, SerialSelector(), InventoryPage(), SerialEvent, SerialHistory(), blank(), LineForm, localDate() (+17 more)

### Community 25 - "Routes & Base Controller"
Cohesion: 0.10
Nodes (10): DeleteDraftDocument, SaveStoreLogo, AccountMappingController, ApprovalController, DraftDocumentController, NotificationController, OperationsHealthController, StoreLogoController (+2 more)

### Community 26 - "Plan, Invariants & Testing"
Cohesion: 0.10
Nodes (29): Idempotent Job Design, Implementation Plan, Document Sequence Service, Phase Gating Implementation Rule, Phase 0 - Foundation, Phase 1 - Store Setup & Accounting Foundation, Phase 3 - Suppliers & Purchasing, Phase 7 - Expenses, Cashier Closing, Bank (+21 more)

### Community 27 - "Accounting & VAT Spec"
Cohesion: 0.12
Nodes (26): Principle: Accounting Is Source of Financial Truth, Principle: Configuration over Hard-Coding, Principle: Posted Financial Documents Never Silently Deleted, cashier_sessions table, tax_codes table, tax_transactions table, Suggested Chart of Accounts Skeleton, Accrual Double-Entry Accounting with Integrated Subledgers (+18 more)

### Community 28 - "All-in-One Master Doc"
Cohesion: 0.10
Nodes (28): ERP Master Documentation (All-in-One), Acceptance Criteria, Sales / Cashier, Cashboxes, cashboxes / bank_accounts, Cashier Sessions and Daily Closing, cashier_sessions, Codex / AI Coding Agent Implementation Prompt (+20 more)

### Community 29 - "Journal & Audit API"
Cohesion: 0.11
Nodes (8): ChangePeriodState, ReverseJournal, AuditController, JournalController, SaveJournalRequest, JournalEntryResource, PerPage, Search

### Community 30 - "Installment Credit Policy Spec"
Cohesion: 0.17
Nodes (25): Principle: Owner Control (permissions, approvals, audit, period locking), audit_logs table, Balloon / Final Payment Schedule, Installment Contract Printout, Installment Contract States (draft -> pending_approval -> active -> completed), Custom Schedule, Customer Credit Policy Evaluation (allowed/warning/blocked), Down Payment (normal customer payment with receipt) (+17 more)

### Community 32 - "Store Setup Phase 1"
Cohesion: 0.10
Nodes (26): Accounting Journal Examples, fiscal_years / accounting_periods, Accountant, Bank Reconciliation, Chart of Accounts Skeleton, Check Accounting Lifecycle (configurable accounts), Configuration Over Hard-Coding, Cutover Checklist (+18 more)

### Community 33 - "Tech Stack & Foundation"
Cohesion: 0.09
Nodes (26): Arabic-First RTL UI, Attachment Access Control (no public paths), attachments (generic file metadata), Audit Invariants, Authentication Security (rate limit, MFA), File/Document Storage (metadata in DB, binaries on disk), Frontend Feature-Based Structure, Internal Stock Locations (not branches) (+18 more)

### Community 34 - "Journal Posting Tests"
Cohesion: 0.13
Nodes (4): JournalEntry, CaptureTreasuryMovements, JournalPostingTest, Illuminate\Database\Eloquent\Relations\HasOne

### Community 35 - "Purchase Order & Receipt Tests"
Cohesion: 0.23
Nodes (3): GoodsReceiptTest, PurchaseOrderTest, Illuminate\Testing\TestResponse

### Community 36 - "Acceptance & Deployment Rules"
Cohesion: 0.12
Nodes (25): Data Protection, Least-Privileged MySQL Application User, Caching Without Redis, Laravel Database Queue (QUEUE_CONNECTION=database), Database Queue Scaling Limits, Queue Worker Segmentation, Deployment - No Docker, Deployment Sequence (+17 more)

### Community 37 - "Accounting Examples & Go-Live"
Cohesion: 0.11
Nodes (24): Accounting Examples, Example: Check Bounced, Example: Check Cleared, Example: Check Deposited, Example: Cash Installment Collection, Example: Installment Sale, Example: Owner Withdrawal, Example: Post-Dated Check Received (+16 more)

### Community 38 - "Frontend App TSConfig"
Cohesion: 0.08
Nodes (23): compilerOptions, allowArbitraryExtensions, allowImportingTsExtensions, erasableSyntaxOnly, jsx, lib, module, moduleDetection (+15 more)

### Community 39 - "HTTP Middleware & Bootstrap"
Cohesion: 0.12
Nodes (11): RequireActiveUser, RequirePermission, Response, SecurityHeaders, Closure, Illuminate\Foundation\Application, Illuminate\Foundation\Configuration\Exceptions, Illuminate\Foundation\Configuration\Middleware (+3 more)

### Community 40 - "Product API"
Cohesion: 0.12
Nodes (5): SuggestSku, ProductController, SaveProductRequest, ProductResource, Illuminate\Http\Resources\Json\JsonResource

### Community 41 - "ERD & Posting Flow"
Cohesion: 0.11
Nodes (22): Accounting Reports (Trial Balance, GL, P&L, Balance Sheet), Accounting as Source of Financial Truth, accounts (Chart of Accounts table), Backend Project Structure, Cash Sale Flow, Customer Profile, customers / customer_contacts, Backend Domain Modules (app/Domains) (+14 more)

### Community 42 - "Phase 3 Purchasing Tasks"
Cohesion: 0.16
Nodes (22): Accounts Payable and Supplier Statement, Inventory / Purchasing User, goods_receipts / goods_receipt_lines, Per-Task Output Template, Perpetual Inventory Accounting (COGS on sale), Phase 3 - Suppliers & Purchasing, PostPurchaseInvoiceAction, Procurement Flow (PO -> Goods Receipt -> Supplier Invoice -> AP -> Payment) (+14 more)

### Community 43 - "Form Request Validation"
Cohesion: 0.13
Nodes (7): SaveUser, UserController, LoginRequest, SavePurchaseOrderRequest, SaveUserRequest, Illuminate\Foundation\Http\FormRequest, Illuminate\Validation\Rules\Password

### Community 44 - "Goods Receipt API"
Cohesion: 0.16
Nodes (5): GoodsReceipt, GoodsReceiptController, SaveGoodsReceiptRequest, GoodsReceiptResource, Illuminate\Http\Resources\Json\AnonymousResourceCollection

### Community 45 - "Stock Document API"
Cohesion: 0.18
Nodes (5): Closure, StockDocumentType, StockDocumentController, SaveStockDocumentRequest, StockDocumentResource

### Community 46 - "Backend NPM Package"
Cohesion: 0.10
Nodes (19): axios, devDependencies, axios, concurrently, laravel-vite-plugin, tailwindcss, @tailwindcss/vite, vite (+11 more)

### Community 47 - "Accounting Setup API"
Cohesion: 0.14
Nodes (5): SaveAccountingMaster, DeleteMasterRecord, SaveStoreSettings, AccountingSetupController, AccountingMasterRequest

### Community 48 - "Frontend Node TSConfig"
Cohesion: 0.10
Nodes (19): compilerOptions, allowImportingTsExtensions, erasableSyntaxOnly, lib, module, moduleDetection, noEmit, noFallthroughCasesInSwitch (+11 more)

### Community 49 - "Frontend Runtime Dependencies"
Cohesion: 0.11
Nodes (19): decimal.js, @fontsource/ibm-plex-sans-arabic, @fontsource/noto-sans-arabic, dependencies, decimal.js, @fontsource/ibm-plex-sans-arabic, @fontsource/noto-sans-arabic, lucide-react (+11 more)

### Community 50 - "Inventory Performance & Invariants"
Cohesion: 0.15
Nodes (19): Performance, MySQL and Laravel Queue, inventory_balances Table, MySQL Query Strategy, Performance Anti-Patterns, Laravel Scheduler (schedule:run cron), Summary Tables, Scheduled Tasks, Phase 2 - Catalog & Inventory (+11 more)

### Community 51 - "Master Index & UX"
Cohesion: 0.15
Nodes (16): Principle: Arabic-First RTL UI, Principle: Check Is a Financial Instrument with Lifecycle, Principle: Installment Is a Receivable Contract, Not a Payment Method, MVP vs Full ERP Phasing, Principle: Performance by Design, Product Goal: Owner Questions Answerable Anytime, Principle: Individually Traceable Appliances via Serials, Single-Store ERP for Palestinian Appliance Retailer (+8 more)

### Community 52 - "ERD & State Machines"
Cohesion: 0.20
Nodes (18): Phase 5 - Installment Engine, Phase 6 - Checks, Check Acceptance Criteria, Approval Invariants, Check Invariants, Installment Invariants, ERD and State Diagrams, CHECK_DEPOSIT_BATCHES (+10 more)

### Community 53 - "Permissions, Approvals & Audit"
Cohesion: 0.13
Nodes (18): Owner / Super Admin, Approval Invariants, Approval Bound to Exact Payload/Version, Configurable Approval Triggers, approvals / approval_steps, Audit Log Coverage, audit_logs, Backend-Authoritative Authorization (+10 more)

### Community 55 - "Product Requirements & Roles"
Cohesion: 0.20
Nodes (15): Actor: Accountant, Actor: Collection User (optional), Functional Scope (Identity, Catalog, Inventory, Purchasing, Sales, Installments, Checks, Accounting, Reports), Actor: Inventory / Purchasing User, Actor: Owner / Super Admin, Actor: Sales / Cashier, approvals / approval_steps tables, roles / permissions / model_has_roles / role_has_permissions (+7 more)

### Community 56 - "Phase 0 Foundation"
Cohesion: 0.15
Nodes (17): Caching Without Redis (file/database cache), Concurrency Locks (SELECT ... FOR UPDATE / lockForUpdate), Deployment Sequence, Atomic Document Number Sequences, document_sequences, Excluded: Docker, Redis, PostgreSQL, Multi-company/Multi-branch, E-commerce, Idempotent Job Design, Internal Notification Center (+9 more)

### Community 57 - "Frontend Dev Tooling"
Cohesion: 0.12
Nodes (17): devDependencies, oxlint, @playwright/test, prettier, tailwindcss, @types/react, @types/react-dom, typescript (+9 more)

### Community 58 - "Supplier API"
Cohesion: 0.17
Nodes (3): SupplierController, SaveSupplierRequest, SupplierResource

### Community 59 - "Inventory & Serials Spec"
Cohesion: 0.22
Nodes (15): PostInventoryAdjustmentAction, Product Master, stock_adjustments / stock_adjustment_lines, stock_counts / stock_count_lines, Internal Stock Locations (Showroom, Warehouse, Reserved, Returns, Damaged, Warranty), Inventory Movement Types (opening, receipt, sale issue, return, transfer, adjustment, warranty...), Negative Stock Policy (prohibited by default), Reorder Suggestions (no auto-purchase without approval) (+7 more)

### Community 60 - "Store Setup Wizard Spec"
Cohesion: 0.22
Nodes (15): Wizard Step 3: Accounting Foundation, Brand Model (independent of category), Wizard Step 5: Catalog Masters, Category Model (parent-child hierarchy), First Login Setup Wizard, Wizard Step 2: Locale and Currency (ILS base; ILS/USD/JOD), Configurable Number Sequences (atomic under concurrency), Wizard Step 8: Opening Financial Balances (+7 more)

### Community 61 - "Reporting & Dashboards Spec"
Cohesion: 0.18
Nodes (16): Reporting and Dashboards, Accounting Reports, Check Cash-Flow Forecast, Check Reports, Async Report Export Strategy, Installment Reports, Inventory Reports, KPI Drill-down to Source Data (+8 more)

### Community 62 - "Security, Audit & Backup Spec"
Cohesion: 0.20
Nodes (16): Security, Audit, Backup and Recovery, Separated Application Logging, Audit Log, Authentication Controls, Backend Policy Enforcement, Database Backup Policy, MFA for Owner/Accountant, MySQL Binary Logs (Point-in-Time Recovery) (+8 more)

### Community 63 - "Purchase Order Workflow"
Cohesion: 0.29
Nodes (3): Approval, PurchaseOrderWorkflow, PurchaseOrder

### Community 64 - "Check Management Spec"
Cohesion: 0.23
Nodes (14): Success Metrics, BounceCheckAction, ClearCheckAction, Idempotency for Critical APIs, idempotency_keys table, Check Accounting Lifecycle (configurable account mappings), Check Capture Fields, Check Controls (privileged clear/bounce, idempotent, no delete after posting) (+6 more)

### Community 65 - "Legal & VAT References"
Cohesion: 0.20
Nodes (15): Arabic-first RTL ERP UI, Legal and Market References, Effective-dated Configurable VAT, Electronic Check Clearing (ECC), Maqam Official Palestinian Legal Portal, Palestinian ERP Market Pattern, Palestinian Monetary Authority (PMA), PMA Returned-Check System (+7 more)

### Community 66 - "Journal Posting & Idempotency"
Cohesion: 0.16
Nodes (15): Check Controls (privileged clear/bounce, idempotent), Control Accounts Reject Manual Journals, Currency Handling (ILS base; USD/JOD transactions), Currency Invariants, currencies / exchange_rates, Financial Invariants, Glossary, Idempotency Keys for Critical Writes (+7 more)

### Community 67 - "Conventions & Notifications"
Cohesion: 0.16
Nodes (14): Notifications and Scheduler, External Notification Adapters (SMS/WhatsApp/Email), Internal Notification Center, notifications Queue, Phase 10 - Optional ERP Extensions, Decimal Types for Money and Quantities, Laravel Modular Monolith Architecture, Project Structure and Coding Conventions (+6 more)

### Community 68 - "Composer Scripts"
Cohesion: 0.15
Nodes (13): scripts, post-autoload-dump, post-root-package-install, post-update-cmd, pre-package-uninstall, test, Illuminate\\Foundation\\ComposerScripts::postAutoloadDump, Illuminate\\Foundation\\ComposerScripts::prePackageUninstall (+5 more)

### Community 69 - "Sales, POS & Returns Spec"
Cohesion: 0.26
Nodes (12): Primary Business Flow, sales_returns / sales_return_lines, Cash Sale Completion, Delivery / Fulfilment Tracking, Exchange (return/credit + new sale), Installment Sale, POS/Sales Screen (barcode/keyboard workflow), Price Controls (cash, installment, minimum price; discount limits) (+4 more)

### Community 72 - "Azure Deployment Stack"
Cohesion: 0.24
Nodes (12): Certbot HTTPS with auto-renew timer, nginx-daftar.conf, Azure app service (Laravel php-fpm), caddy service (automatic HTTPS certificate), Azure maintenance worker service, Azure queue worker service, Azure scheduler service, Azure web service (Nginx) (+4 more)

### Community 73 - "Cash & Bank Treasury"
Cohesion: 0.27
Nodes (3): ManageCashSession, BankAccount, Cashbox

### Community 75 - "Docker Compose Services"
Cohesion: 0.25
Nodes (11): supervisor-daftar.conf (queue, maintenance, scheduler), app service (Laravel php-fpm, ERP_ROLE=app), maintenance worker service, queue worker service, scheduler service (schedule:work), x-laravel shared service template (daftar-api image), Laravel database queue driver, php artisan erp:queue-probe (+3 more)

### Community 76 - "Purchasing & Suppliers Spec"
Cohesion: 0.38
Nodes (10): Accounts Payable & Supplier Statement, Goods Receipt (verify product, qty, serials, condition, location), Procurement Flow (PO -> approval -> GR -> invoice -> matching -> AP -> payment), Purchase Return, Purchasing Controls (price variance, PO approval, duplicate invoice, backdating), Small-Shop Mode (direct GR + invoice without PO), Supplier Invoice, Supplier Master (+2 more)

### Community 77 - "Frontend RTL Entry & Tooling"
Cohesion: 0.20
Nodes (11): frontend/index.html (SPA entry page), Arabic RTL document (lang=ar dir=rtl, theme #145B69), Frontend Vite template README, Oxlint linting configuration, React Compiler (not enabled), React + TypeScript + Vite template, Arabic RTL interface, Isolated browser test store (API 8199 / UI 5199) (+3 more)

### Community 78 - "Customer Payments API"
Cohesion: 0.33
Nodes (3): CustomerPayment, HasMany, CustomerPaymentController

### Community 79 - "Daftar ERP Overview"
Cohesion: 0.22
Nodes (10): robots.txt (allow all crawlers), Backend stock Laravel README, Laravel framework, VAT16 demo tax code (16%, display only), Daftar (دفتر) ERP for a single appliance store, Laravel 12 / Sanctum API backend, Implementation phase gates (IMPLEMENTATION_PROGRESS.md), Pre-operation configuration (store identity, fiscal years, tax versions, account mappings) (+2 more)

### Community 80 - "AWS & Docker Deploy Guides"
Cohesion: 0.22
Nodes (10): deploy/aws/deploy.sh, EC2 Amazon Linux 2023 deployment without Docker (Nginx + PHP 8.3-FPM + MySQL 8.4 + Supervisor), Private GitHub repo with read-only deploy key, php-daftar.ini (upload size, timezone, OPcache), Azure VM docker-compose.yml (1 GB RAM), Per-container memory limits for 1 GB VM, docker-compose.yml (Daftar Docker stack), Docker run guide (docker/README.md) (+2 more)

### Community 81 - "Frontend Package Scripts"
Cohesion: 0.20
Nodes (9): name, private, scripts, build, dev, lint, preview, type (+1 more)

### Community 83 - "Service Providers"
Cohesion: 0.25
Nodes (5): AppServiceProvider, Illuminate\Auth\Notifications\ResetPassword, Illuminate\Cache\RateLimiting\Limit, Illuminate\Support\Facades\RateLimiter, Illuminate\Support\ServiceProvider

### Community 87 - "Demo Year Data"
Cohesion: 0.25
Nodes (9): Demo data README (full year 2026), Balanced trial balance (2,759,585.9229 debit = credit), Cheque lifecycle (receive, deposit, collect, bounce), Credit approval for sale exceeding customer limit, erp_demo_year_2026.sql demo database dump, php artisan erp:demo-year, Installment policy full_price_at_sale, Installment sales (25% down, 6 monthly installments) (+1 more)

### Community 88 - "Oxlint Config"
Cohesion: 0.22
Nodes (8): plugins, rules, react/only-export-components, react/rules-of-hooks, $schema, oxc, typescript, warn

### Community 91 - "Composer Metadata"
Cohesion: 0.25
Nodes (7): description, license, minimum-stability, name, prefer-stable, $schema, type

### Community 92 - "MySQL & Immutable Journals"
Cohesion: 0.29
Nodes (8): Monthly approximate USD/ILS exchange rates, Azure Database for MySQL (recommended managed DB), Azure local db service (localdb profile, trimmed MySQL 8.4), db service (mysql:8.4), mysqld --log-bin-trust-function-creators=1, Immutable posted journals (MySQL-level triggers), Authorized MariaDB to MySQL migration, MySQL 8.4 database

### Community 94 - "Composer Config"
Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

### Community 98 - "Docker Volumes & Backup"
Cohesion: 0.29
Nodes (7): AWS EC2 deployment guide, AWS Security Group (22 own IP, 80/443 public), erp-mysql volume, erp-storage volume, Never run docker compose down -v, php artisan erp:backup, Persistent volumes erp-mysql / erp-storage

### Community 99 - "Overdue Aging & Scheduler"
Cohesion: 0.29
Nodes (7): Collection User, AR Aging (invoice vs installment), Collections Workbench, Customer Statement, Laravel Scheduler (cron / Task Scheduler), Overdue Logic and Aging Buckets, Scheduled Tasks (daily/nightly jobs)

### Community 100 - "VAT Reporting & Exports"
Cohesion: 0.29
Nodes (7): Async Report Export Pattern, Domain Exceptions with Machine Codes (Error Contract), Phase 8 - Reporting & VAT, REST API /api/v1, tax_codes, tax_transactions, VAT Registers (Output / Input)

### Community 101 - "Composer Runtime Requires"
Cohesion: 0.33
Nodes (6): require, brick/math, laravel/framework, laravel/sanctum, laravel/tinker, php

### Community 102 - "Composer Dev Requires"
Cohesion: 0.33
Nodes (6): require-dev, fakerphp/faker, laravel/pint, mockery/mockery, nunomaduro/collision, phpunit/phpunit

### Community 103 - "Sales & Returns Migration"
Cohesion: 0.53
Nodes (4): header(), immutableDocument(), immutableLines(), up()

### Community 104 - "Local Dev & Data Import"
Cohesion: 0.40
Nodes (6): deploy/aws/export-data.ps1, APP_KEY copied from backend/.env, Development and Docker environments are fully separate, scripts/docker-import-local-data.ps1, Local development topology (MySQL 3307/3308, API 8188, UI 5173), scripts/start-development.ps1

### Community 105 - "AWS Production Config"
Cohesion: 0.33
Nodes (6): File ownership model (ec2-user code, apache storage/bootstrap cache, .env 640), deploy/aws/install.sh, MySQL erp app user (GRANT ALL PRIVILEGES on erp.*), Production backend/.env settings (APP_ENV, SANCTUM_STATEFUL_DOMAINS, SESSION_SECURE_COOKIE), LAN access configuration (APP_URL, SANCTUM_STATEFUL_DOMAINS, firewall rule), Least-privilege production DB credentials

### Community 106 - "PSR-4 Autoload"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 107 - "Logging Config"
Cohesion: 0.40
Nodes (4): Monolog\Handler\NullHandler, Monolog\Handler\StreamHandler, Monolog\Handler\SyslogUdpHandler, Monolog\Processor\PsrLogMessageProcessor

### Community 108 - "User Factory"
Cohesion: 0.50
Nodes (3): UserFactory, Illuminate\Database\Eloquent\Factories\Factory, static

### Community 111 - "Test Autoload"
Cohesion: 0.67
Nodes (3): autoload-dev, psr-4, Tests\\

### Community 112 - "Laravel Package Discovery"
Cohesion: 0.67
Nodes (3): extra, laravel, dont-discover

### Community 113 - "Composer Keywords"
Cohesion: 0.67
Nodes (3): keywords, framework, laravel

## Ambiguous Edges - Review These
- `installment_contracts table` → `Suggested Chart of Accounts Skeleton`  [AMBIGUOUS]
  docs/04_DATABASE_DESIGN.md · relation: conceptually_related_to
- `Installment Payment Allocation (selected or oldest-first)` → `Check Accounting Lifecycle (configurable account mappings)`  [AMBIGUOUS]
  docs/07_CHECK_MANAGEMENT.md · relation: conceptually_related_to
- `Supervisor Queue Worker Processes` → `Queue Design (default, reports, exports, notifications, maintenance)`  [AMBIGUOUS]
  docs/20_DEPLOYMENT_NO_DOCKER.md · relation: conceptually_related_to
- `Least-privilege production DB credentials` → `MySQL erp app user (GRANT ALL PRIVILEGES on erp.*)`  [AMBIGUOUS]
  deploy/aws/README.md · relation: conceptually_related_to

## Knowledge Gaps
- **268 isolated node(s):** `$schema`, `name`, `type`, `description`, `laravel` (+263 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **30 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **What is the exact relationship between `installment_contracts table` and `Suggested Chart of Accounts Skeleton`?**
  _Edge tagged AMBIGUOUS (relation: conceptually_related_to) - confidence is low._
- **What is the exact relationship between `Installment Payment Allocation (selected or oldest-first)` and `Check Accounting Lifecycle (configurable account mappings)`?**
  _Edge tagged AMBIGUOUS (relation: conceptually_related_to) - confidence is low._
- **What is the exact relationship between `Supervisor Queue Worker Processes` and `Queue Design (default, reports, exports, notifications, maintenance)`?**
  _Edge tagged AMBIGUOUS (relation: conceptually_related_to) - confidence is low._
- **What is the exact relationship between `Least-privilege production DB credentials` and `MySQL erp app user (GRANT ALL PRIVILEGES on erp.*)`?**
  _Edge tagged AMBIGUOUS (relation: conceptually_related_to) - confidence is low._
- **Why does `Product` connect `Inventory Stock Ledger` to `Test Fixtures & Seed Models`, `Purchasing & Accounting Actions`, `Journal Posting Tests`, `Core API Controllers`, `Purchase Order & Receipt Tests`, `Feature Test Harness`, `Sales, Installments & Credit`, `Product API`, `Demo Seeding & Check Commands`, `Purchase Order API`, `Idempotent Request Controllers`, `Catalog & Purchasing Models`, `Inventory Relations & Audit Models`, `Stock Documents & Fiscal Models`, `Catalog Tests`, `Product Images & Branding`, `Inventory Feature Tests`, `Purchase Order Workflow`?**
  _High betweenness centrality (0.029) - this node is a cross-community bridge._
- **Why does `ERP Master Documentation (All-in-One)` connect `All-in-One Master Doc` to `Database Design Spec`, `Architecture & API Spec`, `Plan, Invariants & Testing`, `Accounting & VAT Spec`, `Installment Credit Policy Spec`, `Tech Stack & Foundation`, `Acceptance & Deployment Rules`, `Accounting Examples & Go-Live`, `ERD & Posting Flow`, `Inventory Performance & Invariants`, `Master Index & UX`, `Product Requirements & Roles`, `Inventory & Serials Spec`, `Store Setup Wizard Spec`, `Reporting & Dashboards Spec`, `Security, Audit & Backup Spec`, `Check Management Spec`, `Legal & VAT References`, `Journal Posting & Idempotency`, `Conventions & Notifications`, `Sales, POS & Returns Spec`, `Purchasing & Suppliers Spec`?**
  _High betweenness centrality (0.024) - this node is a cross-community bridge._
- **Why does `User` connect `Test Fixtures & Seed Models` to `Purchasing & Accounting Actions`, `Core API Controllers`, `Feature Test Harness`, `Sales, Installments & Credit`, `Inventory Stock Ledger`, `Demo Seeding & Check Commands`, `Inventory Relations & Audit Models`, `Auth & Reporting Controllers`, `Supplier Invoices & Attachments`, `Queued Jobs & Backups`, `Product Images & Branding`, `Inventory Feature Tests`, `Journal Posting Tests`, `Purchase Order & Receipt Tests`, `Form Request Validation`, `Supplier Invoice Tests`, `Purchase Order Workflow`, `Identity Feature Tests`, `Business Approvals & Audit`, `Catalog Tests`, `Login MFA Tests`, `Supplier Tests`?**
  _High betweenness centrality (0.021) - this node is a cross-community bridge._