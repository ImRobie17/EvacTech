<?php

namespace App\Providers;

use App\Models\ShelterTransfer;
use App\Services\TransferService;
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

        /**
         * PHASE 2 ITEM 8 -- the transfer alert bar and the sidebar glow.
         *
         * Registered on BOTH staff layouts from one place, so no existing
         * controller had to be edited to make the bar appear on every screen
         * and no future screen can forget it. Super Admin and the public site
         * are deliberately excluded: neither operates shelters.
         *
         * This runs on every page load for these two roles, so it is one query
         * for the open transfers only (never many) with the counting folded in
         * PHP. Overdue is computed on read from departed_at -- there is no
         * scheduler in this project and the UI must not depend on one.
         */
        View::composer(['layouts.staff', 'layouts.cityadmin'], function ($view) {
            $user = auth()->user();

            if (! $user || $user->isSuperAdmin()) {
                return;
            }

            $url = null;
            if ($user->isBarangayPersonnel()) {
                $url = route('barangay.transfers.index');
            } elseif ($user->isCityAdmin()) {
                $url = route('city.transfers.index');
            }

            if (! $url) {
                return;
            }

            $view->with([
                'transferAlerts' => app(TransferService::class)->alertsFor($user),
                'transferAlertsUrl' => $url,
                'transferOverdueMinutes' => ShelterTransfer::overdueMinutes(),
            ]);
        });
    }
}
