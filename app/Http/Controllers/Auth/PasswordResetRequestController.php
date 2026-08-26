<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\PasswordResetRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

/**
 * PHASE 7 ITEM 5 -- raising a password reset request.
 *
 * UNAUTHENTICATED, and it has to be. The whole point is that the person cannot
 * sign in: they have forgotten the password, or item 4 has just locked them out.
 * A request form behind auth would only ever be usable by people who did not
 * need it.
 *
 * What this does NOT do, on purpose:
 *   - It does not send email. There is no mail server in this deployment, and
 *     SystemAlerter is a Super Admin channel, not a way to reach a citizen or a
 *     barangay operator.
 *   - It does not issue a reset token or a link. A link that resets a password
 *     is a credential travelling over a channel nobody controls.
 *   - It does not tell the requester anything about the account.
 *
 * What happens instead: a row lands in an administrator's queue, the
 * administrator telephones the person to confirm the request is really theirs,
 * and then sets a new password with the editor that already exists. Contact is
 * off system by design -- there is no messaging module and this phase does not
 * add one.
 */
class PasswordResetRequestController extends Controller
{
    /**
     * Roles that have somebody above them to handle a request.
     *
     * Super Admin is absent deliberately. Nobody outranks them, so a request
     * from a Super Admin would land in a queue no screen displays. They change
     * their own password from Super Admin > User Management instead.
     */
    private const REQUESTABLE_ROLES = [Role::BARANGAY_PERSONNEL, Role::CITY_ADMIN];

    /**
     * The identical answer given to every submission.
     *
     * Held as a constant so the success path and the silent-no-op path cannot
     * drift into saying different things -- the moment they differ, the form
     * becomes a way to test whether an email address has an account here.
     */
    private const ACKNOWLEDGEMENT = 'If that email address belongs to an EvacTech account, your request has been sent to the administrator who manages it. They will contact you to confirm the request before resetting your password.';

    public function create()
    {
        return view('auth.password-request');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'contact_number' => ['nullable', 'string', 'max:20'],
        ], [
            'email.required' => 'Enter the email address you sign in with.',
        ]);

        $user = User::with('role')
            ->where('email', $data['email'])
            ->where('status', 'active')
            ->whereHas('role', fn ($q) => $q->whereIn('name', self::REQUESTABLE_ROLES))
            ->first();

        // No match: no row, no audit entry, and the SAME message as a success.
        // Anything else here -- a different message, a different response time,
        // a validation error on the email field -- turns this form into an
        // account enumeration oracle for an unauthenticated visitor.
        if (! $user) {
            return redirect()->route('login')->with('success', self::ACKNOWLEDGEMENT);
        }

        // One pending row per account. A second submission REFRESHES the row it
        // already has rather than stacking, so an anxious operator pressing the
        // button five times does not bury the queue -- and updated_at moving
        // forward is genuinely useful information for the administrator.
        $existing = $user->passwordResetRequests()->pending()->first();

        if ($existing) {
            $existing->fill([
                'contact_number' => $data['contact_number'] ?? $existing->contact_number,
                'requested_ip' => $request->ip(),
            ])->touch();
        } else {
            PasswordResetRequest::create([
                'user_id' => $user->id,
                'contact_number' => $data['contact_number'] ?? null,
                'status' => PasswordResetRequest::STATUS_PENDING,
                'requested_ip' => $request->ip(),
            ]);
        }

        // Actor is null -- nobody is signed in. The subject is the account, so
        // the entry appears on that account's trail where an administrator
        // checking "did they really ask?" will look for it.
        AuditLogger::log('password_reset_requested', $user,
            "Password reset requested for {$user->name}");

        return redirect()->route('login')->with('success', self::ACKNOWLEDGEMENT);
    }
}
