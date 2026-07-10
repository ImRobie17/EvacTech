<?php

namespace App\Http\Controllers\Barangay;

use App\Http\Controllers\Concerns\ResolvesCenter;
use App\Http\Controllers\Controller;
use App\Models\EvacuationCenter;

abstract class BarangayController extends Controller
{
    use ResolvesCenter;

    /**
     * The evacuation center this request operates on.
     *
     * Barangay Personnel: their own barangay's center (route arg ignored/validated).
     * City Admin: the center bound in the route, if any.
     *
     * NOTE: this signature now accepts an optional route-bound center so the
     * same feature controllers can be reused by City Admin's "View Details"
     * flow. Existing Barangay Personnel routes pass nothing and behave exactly
     * as before.
     */
    protected function center(?EvacuationCenter $routeCenter = null): ?EvacuationCenter
    {
        return $this->resolveCenter($routeCenter);
    }

    protected function centerOrFail(?EvacuationCenter $routeCenter = null): EvacuationCenter
    {
        return $this->resolveCenterOrFail($routeCenter);
    }

    /**
     * When a City Admin is viewing a specific shelter's screens (the "View
     * Details" flow), the reused Blade views should render inside the City
     * Admin layout and show a "back to shelters" link. Returns view data that
     * every reused index() merges into its compact().
     */
    protected function cityChrome(?EvacuationCenter $center): array
    {
        if ($this->isCityLevel() && $center) {
            return [
                'layout' => 'layouts.cityadmin',
                'backLink' => route('city.shelters.index'),
                'viewingCenter' => $center,
            ];
        }
        return ['layout' => 'layouts.staff', 'backLink' => null, 'viewingCenter' => null];
    }
}
