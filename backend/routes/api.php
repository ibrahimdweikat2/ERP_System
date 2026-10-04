<?php

use App\Domains\Accounting\Actions\SaveAccountingMaster;
use App\Http\Controllers\Api\V1\AccountingSetupController;
use App\Http\Controllers\Api\V1\StoreLogoController;
use App\Http\Controllers\Api\V1\AccountMappingController;
use App\Http\Controllers\Api\V1\ApprovalController;
use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogMasterController;
use App\Http\Controllers\Api\V1\DraftDocumentController;
use App\Http\Controllers\Api\V1\FoundationController;
use App\Http\Controllers\Api\V1\GoodsReceiptController;
use App\Http\Controllers\Api\V1\InventoryController;
use App\Http\Controllers\Api\V1\InvoiceAttachmentController;
use App\Http\Controllers\Api\V1\JournalController;
use App\Http\Controllers\Api\V1\MfaController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductImageController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\RoleController;
use App\Http\Controllers\Api\V1\StockDocumentController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\SupplierInvoiceController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/v1/health', fn () => response()->json(['data' => ['status' => 'ok']]));
// First-party cookie sessions always require CSRF, including requests without an Origin header.
Route::prefix('v1')->middleware('web')->group(function () {
    Route::get('store/branding', [FoundationController::class, 'branding'])->middleware('throttle:60,1');
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('auth/login/mfa', [AuthController::class, 'mfa'])->middleware('throttle:10,1');
    // Password reset by email is switched off; the owner resets passwords from Users.
    // Route::post('auth/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:password-reset');
    // Route::post('auth/reset-password', [AuthController::class, 'reset'])->middleware('throttle:password-reset');
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        // Every signed-in account: company users and the platform superadmin.
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/mfa', [MfaController::class, 'status']);
        Route::post('auth/mfa/setup', [MfaController::class, 'setup'])->middleware('throttle:6,1');
        Route::post('auth/mfa/enable', [MfaController::class, 'enable'])->middleware('throttle:6,1');
        Route::post('auth/mfa/disable', [MfaController::class, 'disable'])->middleware('throttle:6,1');
        // Platform administration: companies and their owners, infrastructure health.
        Route::prefix('platform')->middleware('platform')->group(__DIR__.'/platform.php');
        // Company data. Every query below is filtered to the signed-in user's company.
        Route::middleware('tenant')->group(function () {
            require __DIR__.'/operations.php';
            Route::get('store/context', [FoundationController::class, 'storeContext']);
            Route::get('supplier-invoices', [SupplierInvoiceController::class, 'index'])->middleware('permission:purchasing.view');
            Route::get('supplier-invoices/{supplierInvoice}', [SupplierInvoiceController::class, 'show'])->middleware('permission:purchasing.view');
            Route::post('supplier-invoices', [SupplierInvoiceController::class, 'store'])->middleware('permission:purchasing.invoice');
            Route::put('supplier-invoices/{supplierInvoice}', [SupplierInvoiceController::class, 'update'])->middleware('permission:purchasing.invoice');
            Route::delete('supplier-invoices/{supplierInvoice}', [DraftDocumentController::class, 'destroy'])->defaults('draftKind', 'supplier_invoice')->middleware('permission:purchasing.invoice');
            Route::post('supplier-invoices/{supplierInvoice}/post', [SupplierInvoiceController::class, 'post'])->middleware('permission:purchasing.invoice');
            Route::get('supplier-invoices/{supplierInvoice}/attachments', [InvoiceAttachmentController::class, 'index'])->middleware('permission:purchasing.view');
            Route::post('supplier-invoices/{supplierInvoice}/attachments', [InvoiceAttachmentController::class, 'store'])->middleware('permission:purchasing.invoice');
            Route::get('supplier-invoices/{supplierInvoice}/attachments/{attachment}/download', [InvoiceAttachmentController::class, 'download'])->middleware('permission:purchasing.view');
            Route::get('purchasing/invoice-receipts', [SupplierInvoiceController::class, 'receipts'])->middleware('permission:purchasing.view');
            Route::get('purchasing/invoice-policy', [SupplierInvoiceController::class, 'policy'])->middleware('permission:purchasing.view,settings.manage');
            Route::put('purchasing/invoice-policy', [SupplierInvoiceController::class, 'savePolicy'])->middleware('permission:settings.manage');
            Route::get('purchasing/invoice-policy-accounts', [SupplierInvoiceController::class, 'policyAccounts'])->middleware('permission:settings.manage');
            Route::get('goods-receipts', [GoodsReceiptController::class, 'index'])->middleware('permission:purchasing.view');
            Route::get('goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'show'])->middleware('permission:purchasing.view');
            Route::post('goods-receipts', [GoodsReceiptController::class, 'store'])->middleware('permission:purchasing.receive');
            Route::put('goods-receipts/{goodsReceipt}', [GoodsReceiptController::class, 'update'])->middleware('permission:purchasing.receive');
            Route::delete('goods-receipts/{goodsReceipt}', [DraftDocumentController::class, 'destroy'])->defaults('draftKind', 'goods_receipt')->middleware('permission:purchasing.receive');
            Route::post('goods-receipts/{goodsReceipt}/post', [GoodsReceiptController::class, 'post'])->middleware('permission:purchasing.receive');
            Route::get('purchasing/receiving-locations', [GoodsReceiptController::class, 'locations'])->middleware('permission:purchasing.view,purchasing.receive');
            Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->middleware('permission:purchasing.view');
            Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->middleware('permission:purchasing.view');
            Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->middleware('permission:purchasing.create');
            Route::put('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->middleware('permission:purchasing.create');
            Route::delete('purchase-orders/{purchaseOrder}', [DraftDocumentController::class, 'destroy'])->defaults('draftKind', 'purchase_order')->middleware('permission:purchasing.create');
            foreach (['submit', 'issue', 'decide'] as $event) {
                Route::post('purchase-orders/{purchaseOrder}/'.$event, [PurchaseOrderController::class, 'transition'])->defaults('event', $event)->middleware('permission:'.($event === 'decide' ? 'purchasing.approve' : 'purchasing.create'));
            }
            Route::get('purchasing/order-policy', [PurchaseOrderController::class, 'policy'])->middleware('permission:settings.manage');
            Route::put('purchasing/order-policy', [PurchaseOrderController::class, 'savePolicy'])->middleware('permission:settings.manage');
            Route::get('purchasing/products', [PurchaseOrderController::class, 'products'])->middleware('permission:purchasing.view,purchasing.create');
            Route::get('purchasing/taxes', [PurchaseOrderController::class, 'taxes'])->middleware('permission:purchasing.view,purchasing.create');
            Route::get('purchasing/currencies', [SupplierController::class, 'currencies'])->middleware('permission:purchasing.view,purchasing.create');
            Route::get('suppliers', [SupplierController::class, 'index'])->middleware('permission:purchasing.view');
            Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->middleware('permission:purchasing.view');
            Route::post('suppliers', [SupplierController::class, 'store'])->middleware('permission:purchasing.create');
            Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('permission:purchasing.create');
            Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->middleware('permission:purchasing.delete_supplier');
            Route::get('inventory/settings', [InventoryController::class, 'settings'])->middleware('permission:inventory.view,catalog.view,catalog.manage');
            Route::get('inventory/approval-policy', [InventoryController::class, 'policy'])->middleware('permission:settings.manage');
            Route::put('inventory/approval-policy', [InventoryController::class, 'savePolicy'])->middleware('permission:settings.manage');
            Route::get('inventory/balances', [InventoryController::class, 'balances'])->middleware('permission:inventory.view');
            Route::get('inventory/reorder', [InventoryController::class, 'reorder'])->middleware('permission:inventory.view');
            Route::get('inventory/movements', [InventoryController::class, 'movements'])->middleware('permission:inventory.view');
            Route::get('inventory/serials/suggest', [InventoryController::class, 'suggestSerials'])->middleware('permission:inventory.receive');
            Route::get('inventory/serials', [InventoryController::class, 'serials'])->middleware('permission:inventory.view');
            Route::get('inventory/serials/{serial}/history', [InventoryController::class, 'serialHistory'])->middleware('permission:inventory.view');
            Route::get('approvals', [ApprovalController::class, 'index'])->middleware('permission:approvals.view,approvals.decide');
            Route::post('approvals/{approval}/decide', [ApprovalController::class, 'decide'])->middleware('permission:approvals.decide');
            foreach (['stock-transfers', 'stock-adjustments', 'stock-counts'] as $stockKind) {
                Route::get($stockKind, [StockDocumentController::class, 'index'])->defaults('stockKind', $stockKind)->middleware('permission:inventory.view');
                Route::get($stockKind.'/{id}', [StockDocumentController::class, 'show'])->defaults('stockKind', $stockKind)->middleware('permission:inventory.view');
                Route::post($stockKind, [StockDocumentController::class, 'store'])->defaults('stockKind', $stockKind);
                Route::put($stockKind.'/{id}', [StockDocumentController::class, 'update'])->defaults('stockKind', $stockKind);
                foreach (['submit', 'post'] as $event) {
                    Route::post($stockKind.'/{id}/'.$event, [StockDocumentController::class, $event])->defaults('stockKind', $stockKind);
                }
                Route::delete($stockKind.'/{id}', [StockDocumentController::class, 'destroy'])->defaults('stockKind', $stockKind);
            }
            Route::get('catalog/tax-options', [ProductController::class, 'taxOptions'])->middleware('permission:catalog.view,catalog.manage');
            Route::get('products', [ProductController::class, 'index'])->middleware('permission:catalog.view,catalog.manage');
            Route::get('products/sku-suggestion', [ProductController::class, 'skuSuggestion'])->middleware('permission:catalog.manage');
            Route::get('products/{product}', [ProductController::class, 'show'])->middleware('permission:catalog.view,catalog.manage');
            Route::post('products', [ProductController::class, 'store'])->middleware('permission:catalog.manage');
            Route::put('products/{product}', [ProductController::class, 'update'])->middleware('permission:catalog.manage');
            Route::delete('products/{product}', [ProductController::class, 'destroy'])->middleware('permission:catalog.manage');
            Route::post('products/{product}/image', [ProductImageController::class, 'store'])->middleware('permission:catalog.manage');
            Route::delete('products/{product}/image', [ProductImageController::class, 'destroy'])->middleware('permission:catalog.manage');
            foreach (array_keys(CatalogMasterController::MODELS) as $catalogMaster) {
                Route::get($catalogMaster, [CatalogMasterController::class, 'index'])->defaults('master', $catalogMaster)->middleware('permission:catalog.view,catalog.manage,inventory.view');
                Route::post($catalogMaster, [CatalogMasterController::class, 'store'])->defaults('master', $catalogMaster)->middleware('permission:catalog.manage');
                Route::put($catalogMaster.'/{id}', [CatalogMasterController::class, 'update'])->defaults('master', $catalogMaster)->middleware('permission:catalog.manage');
                Route::delete($catalogMaster.'/{id}', [CatalogMasterController::class, 'destroy'])->defaults('master', $catalogMaster)->middleware('permission:catalog.manage');
            }
            Route::get('account-mappings', [AccountMappingController::class, 'index'])->middleware('permission:accounting.view,settings.manage');
            Route::put('account-mappings/{key}', [AccountMappingController::class, 'update'])->middleware('permission:settings.manage');
            Route::get('journal-entries', [JournalController::class, 'index'])->middleware('permission:accounting.view');
            Route::get('journal-entries/{journalEntry}', [JournalController::class, 'show'])->middleware('permission:accounting.view');
            Route::post('journal-entries', [JournalController::class, 'store'])->middleware('permission:accounting.journal_create');
            Route::put('journal-entries/{journalEntry}', [JournalController::class, 'update'])->middleware('permission:accounting.journal_create');
            Route::delete('journal-entries/{journalEntry}', [DraftDocumentController::class, 'destroy'])->defaults('draftKind', 'journal_entry')->middleware('permission:accounting.journal_create');
            Route::post('journal-entries/{journalEntry}/post', [JournalController::class, 'post'])->middleware('permission:accounting.post');
            Route::post('journal-entries/{journalEntry}/reverse', [JournalController::class, 'reverse'])->middleware('permission:accounting.reverse');
            Route::post('accounting-periods/{period}/lock', [JournalController::class, 'periodState'])->middleware('permission:accounting.period_lock');
            Route::get('store/settings', [AccountingSetupController::class, 'settings'])->middleware('permission:settings.manage');
            Route::put('store/settings', [AccountingSetupController::class, 'saveSettings'])->middleware('permission:settings.manage');
            Route::post('store/logo', [StoreLogoController::class, 'store'])->middleware('permission:settings.manage');
            Route::delete('store/logo', [StoreLogoController::class, 'destroy'])->middleware('permission:settings.manage');
            foreach (array_keys(SaveAccountingMaster::MODELS) as $master) {
                Route::get($master, [AccountingSetupController::class, 'index'])->defaults('master', $master)->middleware('permission:accounting.view');
                Route::post($master, [AccountingSetupController::class, 'store'])->defaults('master', $master)->middleware('permission:settings.manage');
                Route::put($master.'/{id}', [AccountingSetupController::class, 'update'])->defaults('master', $master)->middleware('permission:settings.manage');
                Route::delete($master.'/{id}', [AccountingSetupController::class, 'destroy'])->defaults('master', $master)->middleware('permission:settings.manage');
            }
            Route::get('fiscal-years', [AccountingSetupController::class, 'fiscalYears'])->middleware('permission:accounting.view');
            Route::post('fiscal-years', [AccountingSetupController::class, 'createYear'])->middleware('permission:settings.manage');
            Route::get('accounting-periods', [AccountingSetupController::class, 'periods'])->middleware('permission:accounting.view');
            Route::get('document-sequences', [FoundationController::class, 'sequences'])->middleware('permission:settings.manage');
            Route::put('document-sequences/{sequence}', [FoundationController::class, 'saveSequence'])->middleware('permission:settings.manage');
            Route::apiResource('users', UserController::class)->only(['index', 'store', 'update'])->middleware('permission:users.manage');
            Route::get('role-options', [RoleController::class, 'index'])->middleware('permission:users.manage');
            Route::get('permissions', [RoleController::class, 'permissions'])->middleware('permission:roles.manage');
            Route::apiResource('roles', RoleController::class)->only(['index', 'store', 'update'])->middleware('permission:roles.manage');
            Route::get('audit-logs', [AuditController::class, 'index'])->middleware('permission:audit.view');
        });
    });
});
