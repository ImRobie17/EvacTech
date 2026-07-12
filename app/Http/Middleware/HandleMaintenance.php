<?php

namespace App\Http\Middleware;

use App\Models\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When Laravel maintenance mode is active (php artisan down), Laravel itself
 * short-circuits requests before they reach the app. To let Super Admins keep
 * working, the Super Admin controller calls artisan down with a secret; the
 * bypass cookie is set on their session. This middleware is a belt-and-braces
 * layer for a soft, DB-flag maintenance mode used ONLY for the in-app banner
 * and to force-logout non-super-admins when maintenance is turned on.
 */
class HandleMaintenance
{
    public function handle(Request $request, Closure $next): Response
    {
        // Soft flag stored in cache (set by SystemSettingController::toggleMaintenance)
        if (cache()->get('evactech_maintenance', false)) {
            $user = $request->user();
            $isSuperAdmin = $user && $user->role?->name === Role::SUPER_ADMIN;

            if (! $isSuperAdmin) {
                if ($user) {
                    auth()->logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                }
                // Allow the login page + assets through so the message can render.
                if (! $request->routeIs('login') && ! $request->routeIs('login.attempt')) {
                    return response()->view('maintenance', [], 503);
                }
            }
        }

        return $next($request);
    }
}
