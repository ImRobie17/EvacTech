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
use App\Http\Controllers\SuperAdmin\BarangayManagementController;
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

        // PHASE 7 ITEM 4 -- see routes/city.php for why this is not toggle().
        Route::post('/users/{user}/unlock', [UserManagementController::class, 'unlock'])->name('users.unlock');

        // PHASE 7 ITEM 5 -- City Admin requests are Super Admin's to handle.
        Route::post('/users/reset-requests/{resetRequest}/dismiss', [UserManagementController::class, 'dismissResetRequest'])
            ->name('users.reset-requests.dismiss');

        // PHASE 7 ITEM 6 (deferred half). The ONLY password a Super Admin can
        // change from inside the application is their own, and it requires the
        // current one. Without this there is no in-app recovery for a top-level
        // account at all -- the alternative is a seeder or tinker.
        Route::post('/account/password', [UserManagementController::class, 'updateOwnPassword'])
            ->name('account.password');

        // Audit Logs
        Route::get('/audit', [AuditLogController::class, 'index'])->name('audit.index');

        // System Settings
        Route::get('/settings', [SystemSettingController::class, 'index'])->name('settings.index');
        Route::post('/settings/backup', [SystemSettingController::class, 'backup'])->name('settings.backup');
        Route::post('/settings/maintenance', [SystemSettingController::class, 'toggleMaintenance'])->name('settings.maintenance');
        Route::post('/settings/alerts/{alert}/resolve', [SystemSettingController::class, 'resolveAlert'])->name('settings.alerts.resolve');

        // PHASE 11 - Barangay Management
        Route::get('/barangays', [BarangayManagementController::class, 'index'])->name('barangays.index');
        Route::get('/barangays/create', [BarangayManagementController::class, 'create'])->name('barangays.create');
        Route::post('/barangays', [BarangayManagementController::class, 'store'])->name('barangays.store');
        Route::get('/barangays/{barangay}/edit', [BarangayManagementController::class, 'edit'])->name('barangays.edit');
        Route::put('/barangays/{barangay}', [BarangayManagementController::class, 'update'])->name('barangays.update');
        Route::delete('/barangays/{barangay}', [BarangayManagementController::class, 'destroy'])->name('barangays.destroy');

        // Report Generation
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::post('/reports/generate', [ReportController::class, 'generate'])->name('reports.generate');
    });
