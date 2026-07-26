<?php

namespace App\Http\Controllers\Concerns;

use App\Models\EvacuationCenter;
use App\Models\Household;
use App\Support\ShelterContext;

/**
 * Resolves which evacuation center a request operates on, and enforces access.
 *
 * PHASE 1 ITEM 1 REWRITE. Previously this did:
 *
 *     EvacuationCenter::where('barangay_id', $user->barangay_id)->first()
 *
 * i.e. "the first shelter in my barangay" -- the 1-barangay-to-1-shelter
 * assumption that caused the shelter routing, assignment and check-out bugs.
 *
 * Now:
 * - Barangay Personnel are scoped to the shelters on their roster
 *   (evacuation_center_user). They pick an ACTIVE shelter via the header
 *   switcher; it persists in the session so every screen agrees on context.
 * - City Admin / Super Admin reach any shelter, chosen via a route-bound
 *   {center} or a ?center= id. No selection means "all centers".
 *
 * Resolution order for barangay personnel:
 *   route {center} -> ?center= -> session active -> sole assignment -> first
 *   assignment (persisted, so screens are never blank).
 */
trait ResolvesCenter
{
    // The session key lives on App\Support\ShelterContext, NOT here. A constant
    // declared in a trait cannot be read as Trait::CONST -- PHP throws
    // "Cannot access trait constant ... directly".

    protected function resolveCenter(?EvacuationCenter $routeCenter = null): ?EvacuationCenter
    {
        $user = auth()->user();
        $allowed = $user->assignedCenterIds();

        if ($allowed->isEmpty()) {
            return null; // EnsureShelterAssignment renders the block screen.
        }

        if ($routeCenter) {
            abort_if(! $allowed->contains($routeCenter->id), 403,
                'You are not assigned to this evacuation shelter.');
            $this->setActiveCenterId($routeCenter->id);

            return $routeCenter;
        }

        if ($requested = request()->integer('center')) {
            abort_if(! $allowed->contains($requested), 403,
                'You are not assigned to this evacuation shelter.');
            $this->setActiveCenterId($requested);

            return EvacuationCenter::find($requested);
        }

        $active = ShelterContext::id();
        if ($active && $allowed->contains($active)) {
            return EvacuationCenter::find($active);
        }

        // Nothing chosen yet (or the stored choice was revoked): fall back to the
        // first shelter on the roster and remember it, so no screen renders empty.
        $fallback = $user->assignedCenters()->first();
        if ($fallback) {
            $this->setActiveCenterId($fallback->id);
        }

        return $fallback;
    }

    protected function resolveCenterOrFail(?EvacuationCenter $routeCenter = null): EvacuationCenter
    {
        $center = $this->resolveCenter($routeCenter);
        abort_if(! $center, 422, 'No evacuation center selected or available.');

        return $center;
    }

    protected function setActiveCenterId(int $id): void
    {
        ShelterContext::set($id);
    }

    /**
     * The shelters the current user may switch between. Empty for city-level
     * users, who select shelters from the city list instead of a switcher.
     */
    // -----------------------------------------------------------------
    // Household authorisation
    // -----------------------------------------------------------------

    /**
     * Replaces the old
     *     abort_if($household->origin_barangay_id !== auth()->user()->barangay_id, 403)
     * checks. Those compared the household's ORIGIN barangay against the staff
     * member's barangay, which broke two ways:
     *   1. City Admin has barangay_id = null, so every action 403'd -- this was
     *      the "City Admin check-out gives 403" bug.
     *   2. An evacuee from Barangay A sheltering in Barangay B's shelter could
     *      not be managed by the staff actually looking after them.
     *
     * Access now follows the shelter, which is where the work happens.
     */
    protected function canManageHousehold(Household $household): bool
    {
        // Not yet placed in a shelter (registered / pre-check-in): any assigned
        // staff member may complete the record. Registration is shelter-agnostic.
        if ($household->evacuation_center_id === null) {
            return true;
        }

        return auth()->user()->canAccessCenter($household->evacuation_center_id);
    }

    protected function authorizeHousehold(Household $household): void
    {
        abort_if(! $this->canManageHousehold($household), 403,
            'This household is registered at a shelter you are not assigned to.');
    }
}
