<?php

namespace App\Http\Controllers\PublicSite;

use App\Http\Controllers\Controller;
use App\Models\EvacuationCenter;

class MapController extends Controller
{
    public function index()
    {
        $centers = EvacuationCenter::with('barangay')
            ->where('status', '!=', 'inactive') // active + full both shown; full still matters in a disaster
            ->orderBy('name')
            ->get();

        // Build a plain array for the map's JS. Shelters without coordinates
        // are listed but cannot be pinned; the view labels them accordingly.
        $shelters = $centers->map(function ($c) {
            $pct = $c->capacity > 0 ? (int) round($c->current_occupancy / $c->capacity * 100) : null;
            $tier = $pct === null ? 'unknown' : ($pct > 100 ? 'over' : ($pct >= 90 ? 'full' : ($pct >= 70 ? 'warn' : 'ok')));
            return [
                'id' => $c->id,
                'name' => $c->name,
                'barangay' => $c->barangay?->name,
                'address' => $c->address,
                'lat' => $c->latitude !== null ? (float) $c->latitude : null,
                'lng' => $c->longitude !== null ? (float) $c->longitude : null,
                'capacity' => $c->capacity,
                'occupancy' => $c->current_occupancy,
                'pct' => $pct,
                'tier' => $tier,
                'status' => $c->status,
                'contact_number' => $c->barangay?->office_contact_number,
                'contact_email' => $c->barangay?->office_contact_email,
            ];
        })->values();

        return view('public.map', compact('shelters'));
    }
}
