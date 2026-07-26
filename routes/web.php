<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Barangay\DashboardController;
use App\Http\Controllers\Barangay\EvacueeProfilingController;
use App\Http\Controllers\Barangay\ReliefController;
use App\Http\Controllers\Barangay\ReportController;
use App\Http\Controllers\Barangay\ShelterController;
use Illuminate\Support\Facades\Route;

// ---- Auth (staff) ----
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->name('login.attempt');
});
Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

// ---- Barangay Personnel ----
//
// PHASE 1 ITEM 1 middleware changes:
//   - Added `role:barangay_personnel`. This group previously had NO role check,
//     so any authenticated user (including a citizen-less city_admin session)
//     could reach barangay screens.
//   - Removed `verified`. Seeded accounts are not email-verified, which caused
//     the redirect loop noted in the handoff gotchas.
//   - Added `shelter.assigned`, which stops staff with an empty shelter roster
//     at a "contact your Evacuation Administrator" screen instead of letting
//     every screen render blank.
Route::middleware(['auth', 'role:barangay_personnel', 'shelter.assigned'])
    ->prefix('barangay')
    ->name('barangay.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        // Active shelter switcher (header dropdown). Persists the choice in the
        // session so every screen agrees on which shelter is being operated.
        Route::post('/active-shelter', [DashboardController::class, 'switchCenter'])->name('shelter.switch');

        // Evacuee Profiling
        Route::get('/evacuees', [EvacueeProfilingController::class, 'index'])->name('evacuees.index');
        Route::post('/evacuees', [EvacueeProfilingController::class, 'store'])->name('evacuees.store');
        Route::get('/evacuees/search', [EvacueeProfilingController::class, 'search'])->name('evacuees.search');
        Route::get('/evacuees/{household}', [EvacueeProfilingController::class, 'show'])->name('evacuees.show');
        Route::put('/evacuees/{household}', [EvacueeProfilingController::class, 'update'])->name('evacuees.update');
        Route::delete('/evacuees/{household}', [EvacueeProfilingController::class, 'destroy'])->name('evacuees.destroy');

        // Evacuation Shelter (check-in / check-out / transfer head)
        Route::get('/shelter', [ShelterController::class, 'index'])->name('shelter.index');
        Route::post('/shelter/{household}/check-in', [ShelterController::class, 'checkIn'])->name('shelter.checkin');
        Route::post('/shelter/{household}/check-out', [ShelterController::class, 'checkOut'])->name('shelter.checkout');
        Route::post('/shelter/{household}/transfer-head', [ShelterController::class, 'transferHead'])->name('shelter.transfer');

        // Relief Distribution
        Route::get('/relief', [ReliefController::class, 'index'])->name('relief.index');
        Route::post('/relief/distribute', [ReliefController::class, 'distribute'])->name('relief.distribute');
        Route::post('/relief/receive', [ReliefController::class, 'receive'])->name('relief.receive');
        Route::get('/relief/history/{household}', [ReliefController::class, 'history'])->name('relief.history');
        Route::post('/relief/request-restock', [ReliefController::class, 'requestRestock'])->name('relief.request-restock');
        Route::post('/relief/request-special', [ReliefController::class, 'requestSpecial'])->name('relief.request-special');

        // Report Generation
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::post('/reports/generate', [ReportController::class, 'generate'])->name('reports.generate');
    });
