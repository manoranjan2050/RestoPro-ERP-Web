<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CollectionController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\InsightsController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\VendorController;
use App\Http\Controllers\ReportDownloadController;
use Illuminate\Support\Facades\Route;

/*
 * REST API for the Android app — /api/v1/…  Authenticate with `Authorization: Bearer <token>` from /auth/login.
 * Full reference: docs/API.md
 */
Route::prefix('v1')->name('api.')->group(function () {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:20,1')->name('login');

    Route::middleware(['auth:sanctum', 'throttle:240,1'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/meta', [AuthController::class, 'metaEndpoint']);

        // v1 back office — owner + manager only (cashiers use the sales endpoints).
        Route::middleware('can:back-office')->group(function () {
        Route::get('/dashboard', DashboardController::class);

        // Sales
        Route::get('/collections', [CollectionController::class, 'index']);
        Route::post('/collections', [CollectionController::class, 'store']);
        Route::put('/collections/{collection}', [CollectionController::class, 'update']);
        Route::delete('/collections/{collection}', [CollectionController::class, 'destroy']);

        // Expenses (use POST + _method=PUT when sending a bill photo as multipart)
        Route::get('/expense-categories', [ExpenseController::class, 'categories']);
        Route::post('/expense-categories', [ExpenseController::class, 'storeCategory']);
        Route::put('/expense-categories/{category}', [ExpenseController::class, 'updateCategory']);
        Route::get('/expenses', [ExpenseController::class, 'index']);
        Route::post('/expenses', [ExpenseController::class, 'store']);
        Route::get('/expenses/{expense}', [ExpenseController::class, 'show']);
        Route::put('/expenses/{expense}', [ExpenseController::class, 'update']);
        Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy']);
        Route::post('/expenses/{expense}/mark-paid', [ExpenseController::class, 'markPaid']);
        Route::get('/expenses/{expense}/bill', [ExpenseController::class, 'bill'])->name('expenses.bill');

        // Vendors + khata
        Route::get('/vendors', [VendorController::class, 'index']);
        Route::post('/vendors', [VendorController::class, 'store']);
        Route::get('/vendors/{vendor}', [VendorController::class, 'show']);
        Route::put('/vendors/{vendor}', [VendorController::class, 'update']);
        Route::delete('/vendors/{vendor}', [VendorController::class, 'destroy']);
        Route::get('/vendors/{vendor}/statement', [VendorController::class, 'statement']);
        Route::post('/vendors/{vendor}/payments', [VendorController::class, 'storePayment']);
        Route::put('/vendor-payments/{payment}', [VendorController::class, 'updatePayment']);
        Route::delete('/vendor-payments/{payment}', [VendorController::class, 'destroyPayment']);

        // Staff
        Route::get('/staff', [StaffController::class, 'index']);
        Route::post('/staff', [StaffController::class, 'store']);
        Route::get('/staff/{staff}', [StaffController::class, 'show']);
        Route::put('/staff/{staff}', [StaffController::class, 'update']);
        Route::get('/staff/{staff}/ledger', [StaffController::class, 'ledger']);
        Route::get('/staff/{staff}/attendance', [StaffController::class, 'attendance']);
        Route::get('/staff/{staff}/payments', [StaffController::class, 'payments']);
        Route::post('/staff/{staff}/payments', [StaffController::class, 'storePayment']);
        Route::put('/staff-payments/{payment}', [StaffController::class, 'updatePayment']);
        Route::delete('/staff-payments/{payment}', [StaffController::class, 'destroyPayment']);
        Route::post('/staff/{staff}/share', [StaffController::class, 'share']);
        Route::delete('/staff/{staff}/share', [StaffController::class, 'unshare']);

        // Attendance register
        Route::get('/attendance', [AttendanceController::class, 'day']);
        Route::put('/attendance', [AttendanceController::class, 'set']);
        Route::post('/attendance/mark-all', [AttendanceController::class, 'markAll']);

        // Insights
        Route::get('/ledger', [InsightsController::class, 'ledger']);
        Route::get('/reports/{type}', [InsightsController::class, 'report'])->where('type', '[a-z_]+');
        Route::get('/reports/{type}.{format}', ReportDownloadController::class)
            ->where(['type' => '[a-z_]+', 'format' => 'pdf|xlsx'])->name('reports.download');
        Route::get('/analytics', [InsightsController::class, 'analytics']);
        Route::get('/backups', [InsightsController::class, 'backups']);
        Route::post('/backups', [InsightsController::class, 'backupNow']);
        });
    });
});
