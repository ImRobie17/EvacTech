<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
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

        $account = User::withTrashed()->where('email', $credentials['email'])->first();

        if ($account && $account->isLocked()) {
            $minutes = $account->lockMinutesRemaining();

            return back()
                ->withErrors(['email' =>
                    'This account is temporarily locked after ' . User::MAX_LOGIN_ATTEMPTS
                    . ' failed sign-in attempts. Try again in ' . $minutes . ' minute'
                    . ($minutes === 1 ? '' : 's') . ', or request a password reset below.'])
                ->onlyInput('email');
        }

        if (! auth()->attempt($credentials, $request->boolean('remember'))) {
            
            if ($account && $account->registerFailedLogin()) {
                
                AuditLogger::log('locked', $account,
                    "Account locked for {$account->name} after " . User::MAX_LOGIN_ATTEMPTS
                    . ' failed sign-in attempts');

                return back()
                    ->withErrors(['email' =>
                        'This account has been locked after ' . User::MAX_LOGIN_ATTEMPTS
                        . ' failed sign-in attempts. Try again in ' . User::LOCK_MINUTES
                        . ' minutes, or request a password reset below.'])
                    ->onlyInput('email');
            }

            
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

        
        if (cache()->get('evactech_maintenance', false) && $user->role?->name !== Role::SUPER_ADMIN) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()
                ->withErrors(['email' => 'EvacTech is currently under maintenance. Only system administrators can sign in right now. Please try again later.'])
                ->onlyInput('email');
        }

        
        $user->clearLoginLock();
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
