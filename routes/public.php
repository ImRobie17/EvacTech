<?php

/*
|--------------------------------------------------------------------------
| Public Citizen routes (no authentication)
|--------------------------------------------------------------------------
| Register alongside web.php (see README). Also REMOVE the old root route
|     Route::get('/', fn () => redirect()->route('login'));
| from routes/web.php -- the map below replaces it as the landing page.
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
