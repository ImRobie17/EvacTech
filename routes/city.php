<?php

/*
|--------------------------------------------------------------------------
| City Admin routes  (append into routes/web.php)
|--------------------------------------------------------------------------
| Reuses the Barangay feature controllers for the "View Details" flow by
| binding an explicit {center}. The controllers already resolve access via
| the ResolvesCenter trait, so City Admin can operate any shelter while
| Barangay Personnel stays locked to their own.
*/

use App\Http\Controllers\Barangay\EvacueeProfilingController;
use App\Http\Controllers\Barangay\ReliefController as BarangayReliefController;
use App\Http\Controllers\Barangay\ShelterController as BarangayShelterController;
use App\Http\Controllers\CityAdmin\DashboardController as CityDashboard;
use App\Http\Controllers\CityAdmin\ReliefController as CityRelief;
use App\Http\Controllers\CityAdmin\ReportController as CityReport;
use App\Http\Controllers\CityAdmin\ShelterController as CityShelter;
use App\Http\Controllers\CityAdmin\UserManagementController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:city_admin'])
    ->prefix('city')
    ->name('city.')
    ->group(function () {
        Route::get('/dashboard', [CityDashboard::class, 'index'])->name('dashboard');

        // ---- Evacuation Shelters (city-wide list + add + edit) ----
        Route::get('/shelters', [CityShelter::class, 'index'])->name('shelters.index');
        Route::post('/shelters', [CityShelter::class, 'store'])->name('shelters.store');
        Route::put('/shelters/{center}', [CityShelter::class, 'update'])->name('shelters.update');
        Route::get('/barangays/{barangay}/managers', [CityShelter::class, 'managersForBarangay'])->name('barangays.managers');

        // ---- "View Details": reuse Barangay screens scoped to {center} ----
        Route::prefix('shelters/{center}')->name('shelters.manage.')->group(function () {
            Route::get('/evacuees', [EvacueeProfilingController::class, 'index'])->name('evacuees');
            Route::get('/shelter', [BarangayShelterController::class, 'index'])->name('shelter');
            Route::get('/relief', [BarangayReliefController::class, 'index'])->name('relief');
        });

        // ---- Evacuee Profiling (city-wide, all shelters) ----
        Route::get('/evacuees', [\App\Http\Controllers\CityAdmin\EvacueeProfilingController::class, 'index'])->name('evacuees.index');
        Route::post('/evacuees', [\App\Http\Controllers\CityAdmin\EvacueeProfilingController::class, 'store'])->name('evacuees.store');

        // ---- Relief Distribution (city-wide overview + approvals) ----
        Route::get('/relief', [CityRelief::class, 'index'])->name('relief.index');
        Route::post('/relief/restock/{reliefRequest}', [CityRelief::class, 'approveRestock'])->name('relief.restock.review');
        Route::post('/relief/special/{specialRequest}', [CityRelief::class, 'reviewSpecial'])->name('relief.special.review');

        // ---- Report Generation (city scope, any/all shelters) ----
        Route::get('/reports', [CityReport::class, 'index'])->name('reports.index');
        Route::post('/reports/generate', [CityReport::class, 'generate'])->name('reports.generate');

        // ---- User Management (barangay personnel only) ----
        Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
        Route::post('/users', [UserManagementController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserManagementController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/toggle', [UserManagementController::class, 'toggleStatus'])->name('users.toggle');
    });
