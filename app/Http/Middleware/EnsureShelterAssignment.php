<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Barangay personnel now derive ALL access from their shelter roster, so an
 * account with zero assignments has nothing it can legitimately act on.
 * Rather than letting every screen render empty (and every write 422), stop at
 * the door with a screen that tells them who to contact.
 *
 * Applied to the barangay route group only. Sign-out lives outside that group
 * so an unassigned user can always log back out.
 */
class EnsureShelterAssignment
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isBarangayPersonnel() && ! $user->hasShelterAssignment()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'No evacuation shelter has been assigned to your account.',
                ], 403);
            }

            return response()->view('barangay.no-shelter');
        }

        return $next($request);
    }
}
