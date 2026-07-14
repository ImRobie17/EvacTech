<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class LoginController extends Controller
{
    public function showLoginForm()
    {
        if (auth()->check()) {
            return redirect($this->homeFor(auth()->user()));
        }
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (! auth()->attempt($credentials, $request->boolean('remember'))) {
            return back()
                ->withErrors(['email' => 'The email or password you entered is incorrect.'])
                ->onlyInput('email');
        }

        $request->session()->regenerate();

        $user = auth()->user();

        if ($user->status !== 'active') {
            auth()->logout();
            return back()->withErrors(['email' => 'This account has been deactivated.'])->onlyInput('email');
        }

        // During maintenance only Super Admins may sign in. Rejecting here (rather
        // than letting the request through and bouncing later) gives the user a clear
        // message instead of a confusing redirect or CSRF "Page Expired" error.
        if (cache()->get('evactech_maintenance', false) && $user->role?->name !== Role::SUPER_ADMIN) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()
                ->withErrors(['email' => 'EvacTech is currently under maintenance. Only system administrators can sign in right now. Please try again later.'])
                ->onlyInput('email');
        }

        $user->forceFill(['last_login_at' => now()])->save();
        AuditLogger::log('login', $user, 'User signed in');

        return redirect()->intended($this->homeFor($user));
    }

    public function logout(Request $request)
    {
        AuditLogger::log('logout', $request->user(), 'User signed out');

        auth()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function homeFor($user): string
    {
        return match ($user->role?->name) {
            Role::BARANGAY_PERSONNEL => route('barangay.dashboard'),
            Role::CITY_ADMIN => route('city.dashboard'),
            Role::SUPER_ADMIN => route('super.dashboard'),
            default => route('login'),
        };
    }
}
