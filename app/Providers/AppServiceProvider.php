<?php

namespace App\Providers;

use App\Support\ShelterContext;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /**
         * PHASE 1 ITEM 1 -- the staff layout renders a shelter switcher on every
         * screen. Feeding it from a view composer rather than from each
         * controller's compact() means the Dashboard and Reports screens (which
         * do not call cityChrome) get it too, and no future screen can forget it.
         */
        View::composer('layouts.staff', function ($view) {
            $user = auth()->user();

            $centers = collect();
            $activeId = null;

            if ($user && $user->isBarangayPersonnel()) {
                // Eager load barangay: the layout prints it for every option.
                $centers = $user->assignedCenters()->with('barangay')->get();
                $activeId = ShelterContext::id();

                if (! $activeId || ! $centers->contains('id', $activeId)) {
                    $activeId = $centers->first()?->id;
                }
            }

            $view->with([
                'navCenters' => $centers,
                'navActiveCenterId' => $activeId,
            ]);
        });
    }
}
