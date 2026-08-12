<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetRequestController;
use App\Http\Controllers\Barangay\DashboardController;
use App\Http\Controllers\Barangay\EvacueeProfilingController;
use App\Http\Controllers\Barangay\ReliefController;
use App\Http\Controllers\Barangay\ReportController;
use App\Http\Controllers\Barangay\ShelterController;
use App\Http\Controllers\Barangay\TransferController;
use Illuminate\Support\Facades\Route;

// ---- Auth (staff) ----
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');

    // PHASE 7 ITEM 4. Per-account lockout lives in LoginController; this is the
    // second layer, per IP, and it is the one that answers "brute force" in the
    // paper. The account lock stops guessing at ONE account; this stops a script
    // walking a list of addresses, which no per-account counter can see.
    //
    // 20 a minute is deliberately generous. A shelter office is behind one
    // connection and several people sign in at shift change; a tight limit would
    // lock the desk out of its own system during exactly the surge the system
    // exists for. Guessing at 20/minute is still hopeless.
    //
    // No `verified` anywhere near this -- seeded accounts are not email-verified
    // and it causes a redirect loop.
    Route::post('/login', [LoginController::class, 'login'])
        ->middleware('throttle:20,1')
        ->name('login.attempt');

    // PHASE 7 ITEM 5. Unauthenticated by necessity: someone who has forgotten
    // their password cannot sign in to ask for it to be reset.
    Route::get('/password/request', [PasswordResetRequestController::class, 'create'])
        ->name('password.request');

    // Tighter than the login limiter because there is no legitimate reason to
    // raise more than a handful of these, and the queue an administrator reads
    // is the thing being protected.
    Route::post('/password/request', [PasswordResetRequestController::class, 'store'])
        ->middleware('throttle:5,10')
        ->name('password.request.store');
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

        // PHASE 6 ITEM 7 -- the POST /active-shelter route (barangay.shelter.switch)
        // was removed along with the header switcher that was its only caller. A
        // staff account is assigned to exactly one shelter and moving it is a
        // City Admin reassignment, so there is nothing for a staff member to
        // switch to. Leaving a live endpoint with no interface is how a rule
        // ends up enforced in the UI and not on the server.
        // ResolvesCenter::resolveCenter() still persists the active id itself.

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

        // PHASE 5 ITEM 8b -- presence correction. GET returns the tick list plus
        // the reason it may be blocked; POST writes it. Sits beside check-in and
        // check-out because it is the third member of that family of actions:
        // check-in sets presence, check-out clears it, and this is the only way
        // to correct it in between.
        Route::get('/shelter/{household}/presence', [ShelterController::class, 'presence'])->name('shelter.presence');
        Route::post('/shelter/{household}/presence', [ShelterController::class, 'updatePresence'])->name('shelter.presence.update');

        // Relief Distribution
        Route::get('/relief', [ReliefController::class, 'index'])->name('relief.index');
        /* PHASE 8 ITEM 2. Households this shelter may hand relief to: checked
           in, and here. Both relief pickers used to call evacuees.search, which
           answers a different question (the whole roster, any status) -- fine
           while the list only appeared after someone typed a name, wrong the
           moment it became the default view. GET and read-only, like the other
           type-ahead endpoints in this group. */
        Route::get('/relief/recipients', [ReliefController::class, 'searchRecipients'])->name('relief.recipients');
        Route::post('/relief/distribute', [ReliefController::class, 'distribute'])->name('relief.distribute');
        Route::post('/relief/receive', [ReliefController::class, 'receive'])->name('relief.receive');
        Route::post('/relief/request-restock', [ReliefController::class, 'requestRestock'])->name('relief.request-restock');
        Route::post('/relief/request-special', [ReliefController::class, 'requestSpecial'])->name('relief.request-special');

        // Report Generation
        Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
        Route::post('/reports/generate', [ReportController::class, 'generate'])->name('reports.generate');

        // CSWDO IDP Monitoring Form (Phase 3 item 11a). A separate action from
        // reports.generate: PDF only, fixed layout, its own Blade view. Scoped
        // to the active shelter by ResolvesCenter.
        Route::post('/reports/idp-form', [ReportController::class, 'idp'])->name('reports.idp');

        // ---- Shelter Transfers (Phase 2 item 8) ----
        //
        // Distinct from shelter.transfer above, which is the family-HEAD-role
        // change. These are shelter-to-shelter moves with their own table and
        // their own state machine.
        //
        // The two literal segments are declared BEFORE the {transfer} routes so
        // model binding cannot swallow them.
        Route::get('/transfers', [TransferController::class, 'index'])->name('transfers.index');
        Route::get('/transfers/households', [TransferController::class, 'searchHouseholds'])->name('transfers.households');
        Route::post('/transfers', [TransferController::class, 'store'])->name('transfers.store');

        Route::get('/transfers/{transfer}/members', [TransferController::class, 'members'])->name('transfers.members');
        Route::post('/transfers/{transfer}/confirm', [TransferController::class, 'confirm'])->name('transfers.confirm');
        Route::post('/transfers/{transfer}/refuse', [TransferController::class, 'refuse'])->name('transfers.refuse');
        Route::post('/transfers/{transfer}/depart', [TransferController::class, 'depart'])->name('transfers.depart');
        Route::post('/transfers/{transfer}/receive', [TransferController::class, 'receive'])->name('transfers.receive');

        // PHASE 5 ITEM 8b -- record what happened to someone who did not
        // arrive. Reachable from BOTH ends of the transfer: the origin put
        // those people on the truck and is likeliest to know where they went.
        Route::post('/transfers/{transfer}/resolve-absence', [TransferController::class, 'resolveAbsence'])->name('transfers.resolve');
        Route::post('/transfers/{transfer}/cancel', [TransferController::class, 'cancel'])->name('transfers.cancel');
    });
