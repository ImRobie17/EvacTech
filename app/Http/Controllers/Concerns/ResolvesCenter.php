<?php

namespace App\Http\Controllers\Concerns;

use App\Models\EvacuationCenter;
use App\Models\Role;

/**
 * Resolves which evacuation center a request operates on, and enforces access.
 *
 * - Barangay Personnel: always scoped to their own barangay's center. Any
 *   attempt to touch another center is rejected.
 * - City Admin: may operate on ANY center, chosen explicitly via the route
 *   (e.g. /city/shelters/{center}/evacuees) or a ?center= query param.
 *
 * This lets the same controllers/views serve both roles without duplication.
 */
trait ResolvesCenter
{
    protected function resolveCenter(?EvacuationCenter $routeCenter = null): ?EvacuationCenter
    {
        $user = auth()->user();

        // Barangay Personnel: locked to their barangay's center.
        if ($user->role?->name === Role::BARANGAY_PERSONNEL) {
            $own = EvacuationCenter::where('barangay_id', $user->barangay_id)
                ->orderByRaw("status = 'active' DESC")
                ->orderBy('id')
                ->first();

            // If a specific center was requested, it must be their own.
            if ($routeCenter && $own && $routeCenter->id !== $own->id) {
                abort(403, 'You can only manage your own barangay\'s evacuation center.');
            }

            return $routeCenter && $own && $routeCenter->id === $own->id ? $routeCenter : $own;
        }

        // City Admin / Super Admin: any center. Prefer the route-bound one,
        // else fall back to a ?center= id, else null (meaning "all centers").
        if ($routeCenter) {
            return $routeCenter;
        }

        $id = request()->integer('center');
        return $id ? EvacuationCenter::find($id) : null;
    }

    protected function resolveCenterOrFail(?EvacuationCenter $routeCenter = null): EvacuationCenter
    {
        $center = $this->resolveCenter($routeCenter);
        abort_if(! $center, 422, 'No evacuation center selected or available.');
        return $center;
    }

    protected function isCityLevel(): bool
    {
        return auth()->user()->role?->name !== Role::BARANGAY_PERSONNEL;
    }
}
