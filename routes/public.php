<?php

/*
|--------------------------------------------------------------------------
| Public Citizen routes (no authentication)
|--------------------------------------------------------------------------
| Loaded by the `then:` closure in bootstrap/app.php, inside the `web`
| middleware group. Citizens have no login: nothing in this file carries auth.
|
| The map is the landing page. `/` does NOT redirect to the login screen -- a
| resident checking which shelters are open should never be asked who they are.
*/

use App\Http\Controllers\PublicSite\FindFamilyController;
use App\Http\Controllers\PublicSite\HotlineController;
use App\Http\Controllers\PublicSite\MapController;
use Illuminate\Support\Facades\Route;

Route::get('/', [MapController::class, 'index'])->name('public.map');
Route::get('/find-family', [FindFamilyController::class, 'index'])->name('public.find-family');
Route::post('/find-family', [FindFamilyController::class, 'search'])
    ->middleware('throttle:10,1') // 10 lookups per minute per IP -- anti-scraping
    ->name('public.find-family.search');
Route::get('/hotlines', [HotlineController::class, 'index'])->name('public.hotlines');
