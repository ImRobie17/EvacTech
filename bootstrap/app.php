<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (){
            Route::middleware('web')->group(base_path('routes/city.php'));
            Route::middleware('web')->group(base_path('routes/public.php'));
            Route::middleware('web')->group(base_path('routes/super.php'));
        }
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
            // PHASE 1 ITEM 1: blocks barangay staff with an empty shelter roster.
            'shelter.assigned' => \App\Http\Middleware\EnsureShelterAssignment::class,
        ]);
        // Both cookies are read from Blade during render -- `theme` sets the
        // data-theme attribute and `sidebar` applies the collapsed class before
        // paint. An encrypted cookie cannot be read that way, so neither is
        // encrypted. Neither carries anything sensitive.
        $middleware->encryptCookies(except: ['theme', 'sidebar']);
        $middleware->web(append: [
            \App\Http\Middleware\HandleMaintenance::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
