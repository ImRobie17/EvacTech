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

        // PHASE 7 ITEM 4 -- login attempt limit.
        //
        // Resolved BEFORE attempt() so a lock holds even when the password is
        // finally correct. A lock that a correct guess can walk straight through
        // is not a lock, and an attacker who has just found the password is
        // precisely the case it exists for.
        //
        // withTrashed() is deliberate: users are soft-deleted, and a deleted
        // account must not become an unthrottled guessing target.
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
            // Counted against the ACCOUNT, not the session or the IP. A shelter
            // office shares one connection and often one device; counting by IP
            // would let one operator's typo lock out everyone else at the desk.
            //
            // Nothing is recorded for an email that matches no account -- there
            // is no row to count against, and creating one would build a list of
            // addresses somebody has guessed at.
            if ($account && $account->registerFailedLogin()) {
                // Audited with a null actor: nobody is signed in. The subject is
                // the account that locked, so it surfaces on that account's
                // audit trail, which is where an administrator will look.
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

            // The message stays identical whether the email exists or not, and
            // deliberately does not count down the remaining attempts.
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

        // PHASE 7 ITEM 4 -- "counter resets on success", and it resets here,
        // after the deactivated and maintenance checks. Those two paths call
        // auth()->logout() and are NOT a successful sign-in; clearing the
        // counter for them would hand a deactivated account an unlimited
        // supply of guesses.
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
