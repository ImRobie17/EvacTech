<?php

namespace App\Http\Middleware;

use App\Models\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Usage in routes: ->middleware('role:barangay_personnel')
     * Multiple roles:  ->middleware('role:barangay_personnel,city_admin')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! $user->role || ! in_array($user->role->name, $roles, true)) {
            abort(403, 'You do not have access to this section.');
        }

        if ($user->status !== 'active') {
            auth()->logout();
            return redirect()->route('login')->withErrors(['email' => 'This account has been deactivated.']);
        }

        return $next($request);
    }
}
