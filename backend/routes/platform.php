<?php

// Platform superadmin only (prefix "platform", middleware "platform"; see routes/api.php).
// Runs in platform mode: company tables are reachable only through an explicit company context.

use App\Http\Controllers\Api\V1\AuditController;
use App\Http\Controllers\Api\V1\FoundationController;
use App\Http\Controllers\Api\V1\OperationsHealthController;
use App\Http\Controllers\Api\V1\Platform\CompanyController;
use App\Http\Controllers\Api\V1\Platform\CompanyOwnerController;
use Illuminate\Support\Facades\Route;

Route::get('companies', [CompanyController::class, 'index']);
Route::post('companies', [CompanyController::class, 'store'])->middleware('throttle:30,1');
Route::get('companies/{company}', [CompanyController::class, 'show']);
Route::patch('companies/{company}', [CompanyController::class, 'update']);
Route::get('companies/{company}/owners', [CompanyOwnerController::class, 'index']);
Route::post('companies/{company}/owners', [CompanyOwnerController::class, 'store'])->middleware('throttle:30,1');
// Infrastructure shared by every company.
Route::get('operations/health', [OperationsHealthController::class, 'status']);
Route::post('operations/backups', [OperationsHealthController::class, 'backup'])->middleware('throttle:6,1');
Route::get('system/health', [FoundationController::class, 'health']);
// The platform's own audit trail (rows without a company).
Route::get('audit-logs', [AuditController::class, 'index']);
