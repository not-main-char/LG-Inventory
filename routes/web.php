<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\IncomeController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'sendPasswordResetLink'])->name('password.email');
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth.firebase')->name('logout');

// QR codes open these temporary signed URLs without requiring a login.
Route::get('/shared-reports/{type}/{format}', [ReportController::class, 'share'])->middleware('signed')->name('reports.share');
Route::get('/shared-reports/{type}/{format}/download', [ReportController::class, 'sharedDownload'])->middleware('signed')->name('reports.shared-download');

Route::middleware(['auth.firebase'])->group(function () {
    Route::get('/change-password', [AuthController::class, 'showChangePassword'])->name('change-password');
    Route::post('/change-password', [AuthController::class, 'updatePassword']);
});

Route::middleware(['auth.firebase'])->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    
    Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::get('/inventory/{id}/history', [InventoryController::class, 'stockHistory']);
    Route::get('/inventory/known-conversion', [InventoryController::class, 'knownConversion']); 
    
    Route::middleware(['role:admin'])->group(function () {
        Route::post('/inventory', [InventoryController::class, 'store'])->name('inventory.store');
        Route::post('/inventory/manual-deduction', [InventoryController::class, 'manualDeduction'])->name('inventory.manual-deduction');
        Route::get('/inventory/create', [InventoryController::class, 'create'])->name('inventory.create');
        Route::get('/inventory/{inventory}/edit', [InventoryController::class, 'edit'])->name('inventory.edit');
        Route::put('/inventory/{inventory}', [InventoryController::class, 'update'])->name('inventory.update');
        Route::post('/inventory/{id}/archive', [InventoryController::class, 'archive'])->name('inventory.archive');
        Route::post('/inventory/{id}/restore', [InventoryController::class, 'restore']) ->name('inventory.restore');
        Route::get('/inventory/archived', [InventoryController::class, 'archived']) ->name('inventory.archived');
        Route::post('/inventory/consume-daily', [InventoryController::class, 'runDailyConsumption']);
        Route::post('/inventory/restock', [InventoryController::class, 'restock'])->name('inventory.restock');
    });
    
    Route::get('/income/archived', [IncomeController::class, 'archived'])->name('income.archived');
    Route::post('/income/{id}/archive', [IncomeController::class, 'archive'])->name('income.archive');
    Route::post('/income/{id}/restore', [IncomeController::class, 'restore']) ->name('income.restore');
    Route::resource('income', IncomeController::class)->except(['destroy']);
    Route::get('/income-chart-data', [IncomeController::class, 'chartData']);
    
    Route::middleware(['role:admin'])->group(function () {
        Route::get('/archives', [InventoryController::class, 'unifiedArchives'])->name('archives.index');
        Route::post('/create-admin', [AdminController::class, 'store'])->name('create-admin');
        Route::get('/manage-admins', [AdminController::class, 'manage'])->name('admin.manage');
        Route::post('/manage-admins/{uid}/toggle', [AdminController::class, 'toggleDisable'])->name('admin.toggle');
        Route::post('/notifications/{id}/restore', [NotificationController::class, 'restore']);
    });
    
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/inventory/{format}', [ReportController::class, 'downloadInventory'])->name('reports.inventory');
    Route::get('/reports/sales/{format}', [ReportController::class, 'downloadSales'])->name('reports.sales');

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::delete('/notifications/{id}', [NotificationController::class, 'destroy']);
});