# Graph Report - .  (2026-09-22)

## Corpus Check
- Corpus is ~47,637 words - fits in a single context window. You may not need a graph.

## Summary
- 1259 nodes · 3792 edges · 94 communities (72 shown, 22 thin omitted)
- Extraction: 100% EXTRACTED · 0% INFERRED · 0% AMBIGUOUS · INFERRED: 15 edges (avg confidence: 0.8)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- Document Controllers Core
- Reporting and Queue Jobs
- Sales and Customers
- API Routes and Auth
- Catalog Master Models
- Login MFA and TOTP
- Audit and Save Actions
- Operations Routes and MFA Setup
- Goods Receipt Flow
- Test Harness and Actions
- Form Request Validation
- Purchase Order Approvals
- Supplier Payables
- Supplier Invoice Posting
- User Identity and Roles
- Business Approvals and Returns
- Fiscal and Document Models
- Decimal Money Math
- Journal Posting and Periods
- Product Stock Ledger
- API Controllers Base
- Journal Reversal and Treasury
- Journal and Inventory Lines
- Roles Permissions Seeding
- Frontend Build Config
- User and Supplier Controllers
- Document Sequences
- Accounts and Expenses
- Cashbox and Bank
- Check Lifecycle
- Composer Scripts
- Purchase Order Tests
- Inventory Controller
- Journal Controller
- Identity Tests
- Customer Payments
- Product Controller
- Treasury Controller
- Backup Command
- App Service Provider
- Supplier Tests
- Composer Metadata
- Login MFA Tests
- Operations Policies
- Composer Config
- Users Migrations
- Cache and Jobs Migrations
- Supplier and Sequence Migrations
- Goods Receipt Lines
- Customer Settlements
- Warranty Controller
- PHP Dependencies
- Dev Dependencies
- Sales Migration
- Accounting Setup Tests
- Cash Transfer
- Autoload Namespaces
- Logging Config
- Audit Log Model
- Stock Adjustment Lines
- Stock Count Lines
- Test Autoload
- Laravel Extra
- Package Keywords
- Artisan Entry

## God Nodes (most connected - your core abstractions)
1. `User` - 80 edges
2. `StoreSetting` - 77 edges
3. `BusinessException` - 77 edges
4. `RecordAudit` - 69 edges
5. `Controller` - 67 edges
6. `IdempotentRequest` - 66 edges
7. `Decimal` - 62 edges
8. `Product` - 61 edges
9. `Account` - 47 edges
10. `Role` - 45 edges

## Surprising Connections (you probably didn't know these)
- `GoodsReceiptTest` --references--> `Product`  [EXTRACTED]
  tests/Feature/GoodsReceiptTest.php → app/Domains/Catalog/Models/Product.php
- `InventoryTest` --references--> `Product`  [EXTRACTED]
  tests/Feature/InventoryTest.php → app/Domains/Catalog/Models/Product.php
- `GoodsReceiptTest` --references--> `User`  [EXTRACTED]
  tests/Feature/GoodsReceiptTest.php → app/Models/User.php
- `InventoryTest` --references--> `User`  [EXTRACTED]
  tests/Feature/InventoryTest.php → app/Models/User.php
- `JournalPostingTest` --references--> `User`  [EXTRACTED]
  tests/Feature/JournalPostingTest.php → app/Models/User.php

## Import Cycles
- None detected.

## Communities (94 total, 22 thin omitted)

### Community 0 - "Document Controllers Core"
Cohesion: 0.07
Nodes (19): StockDocumentType, PostStockDocument, StockDocumentType, PreviewStockDocument, StockDocumentType, SaveStockDocument, StockDocumentType, SubmitStockDocument (+11 more)

### Community 1 - "Reporting and Queue Jobs"
Cohesion: 0.05
Nodes (30): AttachSupplierInvoice, DocumentAttachment, QueueProbe, RefreshOperationalAlerts, ReconcileLedgers, ReportEngine, CreateBackup, GenerateReportExport (+22 more)

### Community 2 - "Sales and Customers"
Cohesion: 0.06
Nodes (14): ManageCheck, SettleCustomer, Customer, CreditDecision, ManageInstallmentContract, PostSalesInvoice, ReturnSale, SaveSalesInvoice (+6 more)

### Community 3 - "API Routes and Auth"
Cohesion: 0.08
Nodes (8): SaveAccountingMaster, AccountingSetupController, AccountMappingController, AuditController, FoundationController, InstallmentController, RoleController, Illuminate\Http\JsonResponse

### Community 4 - "Catalog Master Models"
Cohesion: 0.06
Nodes (12): Brand, Category, ProductBarcode, WarrantyPolicy, PaymentAllocation, PurchaseOrderLine, SupplierCreditNoteLine, SupplierInvoiceLine (+4 more)

### Community 5 - "Login MFA and TOTP"
Cohesion: 0.08
Nodes (15): Totp, AuthController, RequireActiveUser, RequirePermission, Response, SecurityHeaders, UserResource, Closure (+7 more)

### Community 6 - "Audit and Save Actions"
Cohesion: 0.11
Nodes (7): Journal, RecordAudit, SaveStoreSettings, Currency, StoreSetting, AccountingTemplateSeeder, Illuminate\Support\Facades\DB

### Community 7 - "Operations Routes and MFA Setup"
Cohesion: 0.10
Nodes (8): CatalogMasterController, MfaController, NotificationController, StreamedResponse, ReportController, SupplierSettlementController, Illuminate\Http\Request, Illuminate\Support\Facades\Route

### Community 8 - "Goods Receipt Flow"
Cohesion: 0.12
Nodes (6): GoodsReceipt, GoodsReceiptController, GoodsReceiptResource, SupplierInvoiceResource, Illuminate\Http\Resources\Json\JsonResource, GoodsReceiptTest

### Community 9 - "Test Harness and Actions"
Cohesion: 0.13
Nodes (16): CreateFiscalYear, DecideInventoryApproval, SaveProduct, PostGoodsReceipt, SaveGoodsReceipt, SavePurchaseOrder, SaveSupplier, ExchangeRate (+8 more)

### Community 10 - "Form Request Validation"
Cohesion: 0.07
Nodes (9): AccountingMasterRequest, LoginRequest, SaveGoodsReceiptRequest, SaveJournalRequest, SaveProductRequest, SaveSupplierRequest, SaveUserRequest, Illuminate\Foundation\Http\FormRequest (+1 more)

### Community 11 - "Purchase Order Approvals"
Cohesion: 0.12
Nodes (6): Approval, PurchaseOrderWorkflow, PurchaseOrder, PurchaseOrderController, SavePurchaseOrderRequest, PurchaseOrderResource

### Community 12 - "Supplier Payables"
Cohesion: 0.11
Nodes (7): PaySupplier, PostSupplierInvoice, ReturnPurchase, SupplierBalances, Supplier, SupplierInvoice, SupplierPayment

### Community 13 - "Supplier Invoice Posting"
Cohesion: 0.15
Nodes (5): SaveSupplierInvoice, PurchasingInvoicePolicy, SupplierInvoiceController, SaveSupplierInvoiceRequest, SupplierInvoiceTest

### Community 14 - "User Identity and Roles"
Cohesion: 0.11
Nodes (9): Unit, StockLocation, User, CatalogMasterSeeder, Illuminate\Database\Eloquent\Factories\HasFactory, Illuminate\Foundation\Auth\User, Illuminate\Foundation\Testing\DatabaseTruncation, Illuminate\Notifications\Notifiable (+1 more)

### Community 15 - "Business Approvals and Returns"
Cohesion: 0.12
Nodes (6): BusinessApproval, AllocateInstallments, GenerateSchedule, BusinessException, Carbon\CarbonImmutable, RuntimeException

### Community 16 - "Fiscal and Document Models"
Cohesion: 0.09
Nodes (5): FiscalYear, StockCount, SupplierCreditNote, Illuminate\Database\Eloquent\Relations\HasMany, Illuminate\Database\Eloquent\Relations\HasOne

### Community 17 - "Decimal Money Math"
Cohesion: 0.13
Nodes (9): fromRoute(), CurrencySnapshot, CalculateDocumentTax, Decimal, BigDecimal, Brick\Math\BigDecimal, Brick\Math\Exception\RoundingNecessaryException, Brick\Math\RoundingMode (+1 more)

### Community 18 - "Journal Posting and Periods"
Cohesion: 0.15
Nodes (7): ChangePeriodState, PostSystemJournal, AccountingPeriod, CustomerLedger, ReceiveCustomerPayment, NextDocumentNumber, Posting

### Community 19 - "Product Stock Ledger"
Cohesion: 0.13
Nodes (4): Product, StockLedger, InventoryBalance, PrepareGoodsReceiptLines

### Community 20 - "API Controllers Base"
Cohesion: 0.18
Nodes (5): ApprovalController, OperationsHealthController, Controller, IdempotentRequest, Illuminate\Validation\Rule

### Community 21 - "Journal Reversal and Treasury"
Cohesion: 0.15
Nodes (5): ReverseJournal, SaveManualJournal, JournalEntry, CaptureTreasuryMovements, JournalPostingTest

### Community 22 - "Journal and Inventory Lines"
Cohesion: 0.11
Nodes (5): JournalLine, InventoryMovement, SerialNumber, StockTransferLine, Illuminate\Database\Eloquent\Relations\BelongsTo

### Community 23 - "Roles Permissions Seeding"
Cohesion: 0.15
Nodes (5): Permission, Role, DatabaseSeeder, Illuminate\Database\Eloquent\Relations\BelongsToMany, CatalogTest

### Community 24 - "Frontend Build Config"
Cohesion: 0.10
Nodes (19): axios, concurrently, laravel-vite-plugin, devDependencies, axios, concurrently, laravel-vite-plugin, tailwindcss (+11 more)

### Community 25 - "User and Supplier Controllers"
Cohesion: 0.15
Nodes (5): SaveUser, SupplierController, UserController, SupplierResource, Illuminate\Http\Resources\Json\AnonymousResourceCollection

### Community 26 - "Document Sequences"
Cohesion: 0.13
Nodes (5): DocumentSequence, BrowserTestSeeder, SequenceSeeder, Illuminate\Database\Seeder, FoundationSequenceTest

### Community 27 - "Accounts and Expenses"
Cohesion: 0.17
Nodes (4): PostJournal, Account, ManageExpense, TaxCode

### Community 28 - "Cashbox and Bank"
Cohesion: 0.21
Nodes (3): ManageCashSession, BankAccount, Cashbox

### Community 30 - "Composer Scripts"
Cohesion: 0.15
Nodes (13): scripts, post-autoload-dump, post-root-package-install, post-update-cmd, pre-package-uninstall, test, Illuminate\\Foundation\\ComposerScripts::postAutoloadDump, Illuminate\\Foundation\\ComposerScripts::prePackageUninstall (+5 more)

### Community 35 - "Customer Payments"
Cohesion: 0.33
Nodes (3): CustomerPayment, HasMany, CustomerPaymentController

### Community 38 - "Backup Command"
Cohesion: 0.28
Nodes (5): BackupStore, Illuminate\Console\Command, PHPUnit\Framework\TestCase, Symfony\Component\Process\Process, DatabaseGuardTest

### Community 39 - "App Service Provider"
Cohesion: 0.25
Nodes (5): AppServiceProvider, Illuminate\Auth\Notifications\ResetPassword, Illuminate\Cache\RateLimiting\Limit, Illuminate\Support\Facades\RateLimiter, Illuminate\Support\ServiceProvider

### Community 41 - "Composer Metadata"
Cohesion: 0.25
Nodes (7): description, license, minimum-stability, name, prefer-stable, $schema, type

### Community 44 - "Composer Config"
Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

### Community 51 - "PHP Dependencies"
Cohesion: 0.33
Nodes (6): require, brick/math, laravel/framework, laravel/sanctum, laravel/tinker, php

### Community 52 - "Dev Dependencies"
Cohesion: 0.33
Nodes (6): require-dev, fakerphp/faker, laravel/pint, mockery/mockery, nunomaduro/collision, phpunit/phpunit

### Community 53 - "Sales Migration"
Cohesion: 0.53
Nodes (4): header(), immutableDocument(), immutableLines(), up()

### Community 56 - "Autoload Namespaces"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 57 - "Logging Config"
Cohesion: 0.40
Nodes (4): Monolog\Handler\NullHandler, Monolog\Handler\StreamHandler, Monolog\Handler\SyslogUdpHandler, Monolog\Processor\PsrLogMessageProcessor

### Community 61 - "Test Autoload"
Cohesion: 0.67
Nodes (3): autoload-dev, psr-4, Tests\\

### Community 62 - "Laravel Extra"
Cohesion: 0.67
Nodes (3): extra, laravel, dont-discover

### Community 63 - "Package Keywords"
Cohesion: 0.67
Nodes (3): keywords, framework, laravel

## Knowledge Gaps
- **47 isolated node(s):** `$schema`, `name`, `type`, `description`, `laravel` (+42 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **22 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `User` connect `User Identity and Roles` to `Document Controllers Core`, `Reporting and Queue Jobs`, `Login MFA and TOTP`, `Audit and Save Actions`, `Operations Routes and MFA Setup`, `Goods Receipt Flow`, `Test Harness and Actions`, `Purchase Order Approvals`, `Supplier Payables`, `Supplier Invoice Posting`, `Business Approvals and Returns`, `Decimal Money Math`, `Journal Reversal and Treasury`, `Roles Permissions Seeding`, `User and Supplier Controllers`, `Document Sequences`, `Accounts and Expenses`, `Purchase Order Tests`, `Identity Tests`, `Supplier Tests`, `Login MFA Tests`, `Audit Log Model`?**
  _High betweenness centrality (0.065) - this node is a cross-community bridge._
- **Why does `StoreSetting` connect `Audit and Save Actions` to `Document Controllers Core`, `Sales and Customers`, `API Routes and Auth`, `Catalog Master Models`, `Login MFA and TOTP`, `Operations Routes and MFA Setup`, `Test Harness and Actions`, `Purchase Order Approvals`, `Supplier Payables`, `Supplier Invoice Posting`, `User Identity and Roles`, `Business Approvals and Returns`, `Fiscal and Document Models`, `Decimal Money Math`, `Journal Posting and Periods`, `Product Stock Ledger`, `API Controllers Base`, `Journal Reversal and Treasury`, `Accounts and Expenses`, `Cashbox and Bank`, `Inventory Controller`, `Accounting Setup Tests`, `Cash Transfer`?**
  _High betweenness centrality (0.058) - this node is a cross-community bridge._
- **Why does `Product` connect `Product Stock Ledger` to `Document Controllers Core`, `Sales and Customers`, `API Routes and Auth`, `Catalog Master Models`, `Audit and Save Actions`, `Goods Receipt Flow`, `Test Harness and Actions`, `Purchase Order Approvals`, `Supplier Payables`, `User Identity and Roles`, `Business Approvals and Returns`, `Fiscal and Document Models`, `Decimal Money Math`, `Journal Posting and Periods`, `API Controllers Base`, `Journal and Inventory Lines`, `Roles Permissions Seeding`, `Cashbox and Bank`, `Product Controller`, `Operations Policies`, `Stock Adjustment Lines`, `Stock Count Lines`?**
  _High betweenness centrality (0.053) - this node is a cross-community bridge._
- **What connects `$schema`, `name`, `type` to the rest of the system?**
  _47 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Document Controllers Core` be split into smaller, more focused modules?**
  _Cohesion score 0.07092907092907093 - nodes in this community are weakly interconnected._
- **Should `Reporting and Queue Jobs` be split into smaller, more focused modules?**
  _Cohesion score 0.05110809588421529 - nodes in this community are weakly interconnected._
- **Should `Sales and Customers` be split into smaller, more focused modules?**
  _Cohesion score 0.06453634085213032 - nodes in this community are weakly interconnected._