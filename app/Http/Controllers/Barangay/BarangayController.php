<?php

namespace App\Http\Controllers\Barangay;

use App\Http\Controllers\Concerns\ResolvesCenter;
use App\Http\Controllers\Controller;
use App\Models\EvacuationCenter;
use Illuminate\Http\Request;

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

    /**
     * Switch the active shelter, then return to where the user came from. Lives on
     * the base controller so every barangay screen shares one endpoint.
     */
    public function switchCenter(Request $request)
    {
        $data = $request->validate([
            'center_id' => ['required', 'integer'],
        ]);

        $user = $request->user();
        abort_if(! $user->canAccessCenter($data['center_id']), 403,
            'You are not assigned to this evacuation shelter.');

        $this->setActiveCenterId((int) $data['center_id']);
        $center = EvacuationCenter::find($data['center_id']);

        return back()->with('success', 'Now working in ' . ($center?->name ?? 'the selected shelter') . '.');
    }
}
