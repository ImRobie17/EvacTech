<?php

namespace App\Http\Controllers\Barangay;

use App\Http\Controllers\Controller;
use App\Models\EvacuationCenter;

abstract class BarangayController extends Controller
{
    /**
     * The evacuation center this staff member operates.
     * Convention: barangay personnel are scoped to their barangay's center.
     * If a barangay ever has multiple centers, extend users with a
     * managed_center_id -- for now first active center wins.
     */
    protected function center(): ?EvacuationCenter
    {
        $user = auth()->user();

        if (! $user->barangay_id) {
            return null;
        }

        return EvacuationCenter::where('barangay_id', $user->barangay_id)
            ->orderByRaw("status = 'active' DESC")
            ->orderBy('id')
            ->first();
    }

    protected function centerOrFail(): EvacuationCenter
    {
        $center = $this->center();
        abort_if(! $center, 422, 'No evacuation center is registered for your barangay yet. Ask the City Admin to add one.');
        return $center;
    }
}
