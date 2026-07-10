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
            // Super Admin lands on city dashboard as a placeholder until built:
            Role::SUPER_ADMIN => route('city.dashboard'),
            default => route('login'),
        };
    }
}
