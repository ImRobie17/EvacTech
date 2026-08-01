<?php

/*
|--------------------------------------------------------------------------
| Super Admin routes
|--------------------------------------------------------------------------
| Loaded by the `then:` closure in bootstrap/app.php, inside the `web`
| middleware group.
|
| `role:super_admin` only. NEVER add `verified` here -- seeded accounts have no
| verified email and the redirect loop that causes is one of the project's
| recorded gotchas.
*/

use App\Http\Controllers\SuperAdmin\AuditLogController;
use App\Http\Controllers\SuperAdmin\DashboardController;
use App\Http\Controllers\SuperAdmin\ReportController;
use App\Http\Controllers\SuperAdmin\SystemSettingController;
use App\Http\Controllers\SuperAdmin\UserManagementController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:super_admin'])
    ->prefix('super')
    ->name('super.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        // User Management (city admin + barangay personnel, promote/demote)
        Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
        Route::post('/users', [UserManagementController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserManagementController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/toggle', [UserManagementController::class, 'toggleStatus'])->name('users.toggle');

        // Audit Logs
        Route::get('/audit', [AuditLogController::class, 'index'])->name('audit.index');

        // System Settings
        Route::get('/settings', [SystemSettingController::class, 'index'])->name('settings.index');
        Route::post('/settings/backup', [SystemSettingController::class, 'backup'])->name('settings.backup');
        Route::post('/settings/maintenance', [SystemSettingController::class, 'toggleMaintenance'])->name('settings.maintenance');
        Route::post('/settings/alerts/{alert}/resolve', [SystemSettingController::class, 'resolveAlert'])->name('settings.alerts.resolve');

        // Report Generation
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::post('/reports/generate', [ReportController::class, 'generate'])->name('reports.generate');
    });
