<?php

namespace App\Http\Controllers\Barangay;

use App\Http\Controllers\Concerns\ResolvesCenter;
use App\Http\Controllers\Controller;
use App\Models\EvacuationCenter;

/**
 * Base for the Barangay Personnel screens.
 *
 * These controllers are reached ONLY through the barangay route group, which is
 * gated by role:barangay_personnel. City Admin has its own controllers and views
 * (CityAdmin\ShelterDetailController), so nothing here branches on role.
 *
 * The old cityChrome() helper -- which swapped the layout, injected a back link
 * and let City Admin render these views -- is gone. That mechanism is what caused
 * the shelter routing bugs.
 */
abstract class BarangayController extends Controller
{
    use ResolvesCenter;

    /**
     * The shelter this request operates on: the staff member's active shelter,
     * chosen from their roster via the header switcher and persisted in session.
     */
    protected function center(): ?EvacuationCenter
    {
        return $this->resolveCenter();
    }

    protected function centerOrFail(): EvacuationCenter
    {
        return $this->resolveCenterOrFail();
    }

    /*
     * PHASE 6 ITEM 7 -- switchCenter() removed.
     *
     * It backed the header shelter switcher, which is gone: a staff account
     * holds exactly one shelter, and moving it is a reassignment performed by
     * City Admin, not a choice made from the header. Its route
     * (barangay.shelter.switch) went with it.
     *
     * Nothing else called it. resolveCenter() in ResolvesCenter still persists
     * the active id, and still falls back to the first roster entry when the
     * stored one is no longer assigned -- which is what makes a reassignment
     * take effect on the staff member's next page load with nothing to click.
     */
}
