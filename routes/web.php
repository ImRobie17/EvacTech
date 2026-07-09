<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Barangay\DashboardController;
use App\Http\Controllers\Barangay\EvacueeProfilingController;
use App\Http\Controllers\Barangay\ReliefController;
use App\Http\Controllers\Barangay\ReportController;
use App\Http\Controllers\Barangay\ShelterController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('login'));

// ---- Auth (staff) ----
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->name('login.attempt');
});
Route::post('/logout', [LoginController::class, 'logout'])->middleware('auth')->name('logout');

// ---- Barangay Personnel ----
Route::middleware(['auth', 'verified'])
    ->prefix('barangay')
    ->name('barangay.')
    ->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

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

        // Report Generation
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::post('/reports/generate', [ReportController::class, 'generate'])->name('reports.generate');
    });
