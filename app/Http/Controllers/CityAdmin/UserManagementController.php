<?php

namespace App\Http\Controllers\CityAdmin;

use App\Http\Controllers\Controller;
use App\Models\EvacuationCenter;
use App\Models\PasswordResetRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    /** Barangay Personnel accounts only -- City Admin cannot see other admins. */
    private function baseQuery()
    {
        return User::with(['role', 'assignedCenters.barangay'])
            ->whereHas('role', fn ($q) => $q->where('name', Role::BARANGAY_PERSONNEL));
    }

    public function index(Request $request)
    {
        $query = $this->baseQuery();

        if ($search = trim((string) $request->input('q'))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }

        // Filter by SHELTER assignment rather than by barangay: a staff member can
        // now be rostered to several shelters across several barangays.
        if ($centerId = $request->input('shelter')) {
            $query->whereHas('assignedCenters', fn ($q) => $q->where('evacuation_centers.id', $centerId));
        }
        if ($request->input('unassigned')) {
            $query->whereDoesntHave('assignedCenters');
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $users = $query->orderBy('name')->paginate(15)->withQueryString();
        $shelters = EvacuationCenter::with('barangay')->orderBy('name')->get();

        // PHASE 7 ITEM 5. Barangay requests are City Admin's to handle.
        //
        // In the UI, not by email. SystemAlerter reaches Super Admins only, and
        // only when EVACTECH_ALERT_EMAILS is configured -- anything City Admin
        // must see belongs on the page they are already looking at.
        //
        // Not paginated: a pending queue that needs a second page is a queue
        // nobody is working, and a second paginator would fight $users for the
        // ?page parameter.
        $resetRequests = PasswordResetRequest::with('user')
            ->pending()
            ->forRoles([Role::BARANGAY_PERSONNEL])
            ->orderBy('created_at')
            ->get();

        return view('cityadmin.users.index', compact('users', 'shelters', 'resetRequests'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'shelters' => ['required', 'array', 'min:1'],
            'shelters.*' => ['integer', 'exists:evacuation_centers,id'],
            'password' => ['required', 'string', 'min:8'],
        ], [
            'shelters.required' => 'Assign at least one evacuation shelter, or the account cannot operate anything.',
        ]);

        $role = Role::where('name', Role::BARANGAY_PERSONNEL)->firstOrFail();

        $user = DB::transaction(function () use ($data, $role) {
            $user = User::create([
                'role_id' => $role->id,
                // Nominal barangay only, derived from the first shelter assigned.
                // It no longer grants any access -- see User::canAccessCenter().
                'barangay_id' => EvacuationCenter::find($data['shelters'][0])?->barangay_id,
                'name' => $data['name'],
                'email' => $data['email'],
                'contact_number' => $data['contact_number'] ?? null,
                'password' => Hash::make($data['password']),
                'status' => 'active',
            ]);

            $this->syncShelters($user, $data['shelters']);

            return $user;
        });

        AuditLogger::log('created', $user,
            "Created camp manager account for {$user->name} (" . count($data['shelters']) . ' shelter assignment(s))');

        return back()->with('success', "Account for {$user->name} created.");
    }

    public function update(Request $request, User $user)
    {
        $this->authorizeTarget($user);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'shelters' => ['required', 'array', 'min:1'],
            'shelters.*' => ['integer', 'exists:evacuation_centers,id'],
            'status' => ['required', 'in:active,inactive'],
            'password' => ['nullable', 'string', 'min:8'],
        ], [
            'shelters.required' => 'Assign at least one evacuation shelter, or the account cannot operate anything.',
        ]);

        DB::transaction(function () use ($data, $user) {
            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'contact_number' => $data['contact_number'] ?? null,
                'barangay_id' => EvacuationCenter::find($data['shelters'][0])?->barangay_id,
                'status' => $data['status'],
            ]);
            $passwordChanged = ! empty($data['password']);
            if ($passwordChanged) {
                $user->password = Hash::make($data['password']);
            }
            $user->save();

            $this->syncShelters($user, $data['shelters']);

            // PHASE 7 ITEMS 4 AND 5. Setting a new password IS the resolution,
            // so it closes the queue entry and clears any lock in the same
            // transaction. There is deliberately no separate "mark completed"
            // button: an administrator who helps someone and then forgets to
            // tidy the queue would leave a request pending forever, and the next
            // administrator would telephone the same person again.
            if ($passwordChanged) {
                $this->resolveResetRequests($user);
            }
        });

        AuditLogger::log('updated', $user,
            "Updated camp manager account {$user->name}; shelters: " . $user->assignedCenters()->pluck('name')->implode(', '));

        return back()->with('success', "Account for {$user->name} updated.");
    }

    public function toggleStatus(User $user)
    {
        $this->authorizeTarget($user);
        $user->update(['status' => $user->status === 'active' ? 'inactive' : 'active']);

        AuditLogger::log('updated', $user, "Set {$user->name} to {$user->status}");

        return back()->with('success', "{$user->name} is now {$user->status}.");
    }

    // -----------------------------------------------------------------

    /**
     * Reassignment happens here: syncing replaces the roster, so removing a
     * shelter from the list revokes that staff member's access to it. If their
     * currently-active shelter is revoked, ResolvesCenter falls back to another
     * shelter on their roster on the next request.
     */
    private function syncShelters(User $user, array $shelterIds): void
    {
        $user->assignedCenters()->sync(
            collect($shelterIds)->unique()->mapWithKeys(fn ($id) => [$id => [
                'assigned_by' => auth()->id(),
                'assigned_at' => now(),
            ]])->all()
        );
        $user->forgetAssignedCenterCache();
    }

    /** Guard: City Admin may only ever act on barangay personnel rows. */
    /**
     * PHASE 7 ITEM 4 -- release a lock early.
     *
     * Kept apart from toggleStatus(). A locked account is still ACTIVE; it is a
     * person who mistyped a password three times, not an account somebody
     * disabled. Folding the two together would let one button quietly do the
     * other's job.
     */
    public function unlock(User $user)
    {
        $this->authorizeTarget($user);

        if (! $user->isLocked() && (int) $user->failed_login_attempts === 0) {
            return back()->with('success', "{$user->name}'s account is not locked.");
        }

        $user->clearLoginLock();
        AuditLogger::log('unlocked', $user, "Unlocked sign-in for {$user->name}");

        return back()->with('success', "{$user->name} can sign in again.");
    }

    /**
     * PHASE 7 ITEM 5 -- close a request WITHOUT resetting anything.
     *
     * For the case where the telephone call goes badly: the person did not raise
     * it, or no longer needs it. Dismissing changes no credential and no lock,
     * which is the whole point -- the destructive half of this flow stays behind
     * the account editor.
     */
    public function dismissResetRequest(PasswordResetRequest $resetRequest)
    {
        // Users are SOFT deleted, so the table's cascadeOnDelete never fires and
        // a request can outlive its account. The index queries filter these out
        // through whereHas, but a direct POST would reach authorizeTarget with a
        // null user and 500. Treat an orphan as gone.
        abort_if($resetRequest->user === null, 404);

        $this->authorizeTarget($resetRequest->user);

        abort_if($resetRequest->status !== PasswordResetRequest::STATUS_PENDING, 404);

        $resetRequest->update([
            'status' => PasswordResetRequest::STATUS_DISMISSED,
            'handled_by' => auth()->id(),
            'handled_at' => now(),
        ]);

        AuditLogger::log('dismissed', $resetRequest->user,
            "Dismissed password reset request from {$resetRequest->user->name}");

        return back()->with('success', 'Request dismissed. No password was changed.');
    }

    /** Close every pending request for this account and clear any lock. */
    private function resolveResetRequests(User $user): void
    {
        $user->clearLoginLock();

        $user->passwordResetRequests()->pending()->update([
            'status' => PasswordResetRequest::STATUS_COMPLETED,
            'handled_by' => auth()->id(),
            'handled_at' => now(),
        ]);
    }

    private function authorizeTarget(User $user): void
    {
        abort_if($user->role?->name !== Role::BARANGAY_PERSONNEL, 403,
            'You can only manage camp manager accounts.');
    }
}
