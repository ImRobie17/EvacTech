<?php

namespace App\Http\Middleware;

use App\Models\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Soft maintenance mode.
 *
 * When the `evactech_maintenance` cache flag is on:
 *   - Super Admins pass through untouched (they need access to turn it back off).
 *   - Everyone else sees the 503 maintenance page, INCLUDING at the login screen.
 *   - Any already-authenticated non-super-admin is logged out on their next request.
 *
 * Why login is handled the way it is: a super admin still has to be able to sign in
 * while maintenance is on, so the login form and POST are allowed through for guests.
 * The moment credentials resolve to a non-super-admin, the LoginController rejects
 * them (see the maintenance check there) -- we do NOT invalidate the session here and
 * then let the request fall through to the auth routes, because destroying the CSRF
 * token mid-request makes Laravel's CSRF middleware reject the submission with a
 * confusing "419 Page Expired" instead of showing the maintenance message.
 */
class HandleMaintenance
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! cache()->get('evactech_maintenance', false)) {
            return $next($request);
        }

        $user = $request->user();

        // Super Admins keep full access.
        if ($user && $user->role?->name === Role::SUPER_ADMIN) {
            return $next($request);
        }

        // Guests may reach the login form/POST so a Super Admin can still sign in.
        // Non-super-admin credentials are rejected inside LoginController.
        if (! $user && $request->routeIs('login', 'login.attempt')) {
            return $next($request);
        }

        // Authenticated non-super-admin: sign them out, then show maintenance.
        // Returning immediately means the fresh CSRF token ships with THIS response,
        // so nothing is left mismatched.
        if ($user) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->view('maintenance', [], 503);
    }
}
