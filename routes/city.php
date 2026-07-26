<?php

/*
|--------------------------------------------------------------------------
| City Admin routes
|--------------------------------------------------------------------------
| NO BARANGAY CONTROLLER REUSE. City Admin previously reached shelter detail by
| pointing `city.shelters.manage.*` at the Barangay controllers and Blade views.
| Every action in those shared views then had to work out which role it was
| rendering for in order to pick a route, and each new action was a fresh chance
| to get it wrong. That single cause produced three bugs: the back link landing on
| /barangay/shelter, the check-out 403, and Edit Family Group navigating away
| instead of opening in place.
|
| City Admin now has its own controller and views. Same database tables,
| separate presentation, zero role branching.
*/

use App\Http\Controllers\CityAdmin\DashboardController as CityDashboard;
use App\Http\Controllers\CityAdmin\EvacueeProfilingController as CityEvacuees;
use App\Http\Controllers\CityAdmin\ReliefController as CityRelief;
use App\Http\Controllers\CityAdmin\ReportController as CityReport;
use App\Http\Controllers\CityAdmin\ShelterController as CityShelter;
use App\Http\Controllers\CityAdmin\ShelterDetailController as CityShelterDetail;
use App\Http\Controllers\CityAdmin\UserManagementController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:city_admin'])
    ->prefix('city')
    ->name('city.')
    ->group(function () {
        Route::get('/dashboard', [CityDashboard::class, 'index'])->name('dashboard');

        // ---- Evacuation Shelters: city-wide list, add, edit ----
        Route::get('/shelters', [CityShelter::class, 'index'])->name('shelters.index');
        Route::post('/shelters', [CityShelter::class, 'store'])->name('shelters.store');
        Route::put('/shelters/{center}', [CityShelter::class, 'update'])->name('shelters.update');

        // Barangay personnel available to staff a shelter.
        Route::get('/personnel', [CityShelter::class, 'assignableStaff'])->name('personnel.assignable');

        // ---- Single shelter detail (City Admin's own page) ----
        // Tabs are ?tab=households|relief on the show route.
        Route::get('/shelters/{center}/detail', [CityShelterDetail::class, 'show'])->name('shelters.show');

        Route::prefix('shelters/{center}')->name('shelters.')->group(function () {
            // IMPORTANT: /households/search is declared BEFORE /households/{household}
            // so the literal segment is not swallowed by the model binding.
            Route::get('/households/search', [CityShelterDetail::class, 'searchHouseholds'])->name('households.search');
            Route::get('/households/{household}', [CityShelterDetail::class, 'household'])->name('households.show');
            Route::put('/households/{household}', [CityShelterDetail::class, 'updateHousehold'])->name('households.update');
            Route::post('/households/{household}/check-in', [CityShelterDetail::class, 'checkIn'])->name('households.checkin');
            Route::post('/households/{household}/check-out', [CityShelterDetail::class, 'checkOut'])->name('households.checkout');

            Route::get('/relief/recipients', [CityShelterDetail::class, 'searchReliefRecipients'])->name('relief.recipients');
            Route::post('/relief/receive', [CityShelterDetail::class, 'receiveRelief'])->name('relief.receive');
            Route::post('/relief/distribute', [CityShelterDetail::class, 'distributeRelief'])->name('relief.distribute');
        });

        // ---- Evacuee Profiling (city-wide, all shelters) ----
        Route::get('/evacuees', [CityEvacuees::class, 'index'])->name('evacuees.index');
        Route::post('/evacuees', [CityEvacuees::class, 'store'])->name('evacuees.store');

        // ---- Relief Distribution (city-wide overview + approvals) ----
        Route::get('/relief', [CityRelief::class, 'index'])->name('relief.index');
        Route::post('/relief/restock/{reliefRequest}', [CityRelief::class, 'approveRestock'])->name('relief.restock.review');
        Route::post('/relief/special/{specialRequest}', [CityRelief::class, 'reviewSpecial'])->name('relief.special.review');

        // ---- Report Generation ----
        Route::get('/reports', [CityReport::class, 'index'])->name('reports.index');
        Route::post('/reports/generate', [CityReport::class, 'generate'])->name('reports.generate');

        // ---- User Management (barangay personnel only) ----
        Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
        Route::post('/users', [UserManagementController::class, 'store'])->name('users.store');
        Route::put('/users/{user}', [UserManagementController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/toggle', [UserManagementController::class, 'toggleStatus'])->name('users.toggle');
    });
