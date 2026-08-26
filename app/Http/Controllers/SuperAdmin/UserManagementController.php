<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Barangay;
use App\Models\PasswordResetRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    /** Super Admin manages City Admin + Barangay Personnel (not other Super Admins). */
    private function baseQuery()
    {
        return User::with(['barangay', 'role'])
            ->whereHas('role', fn ($q) => $q->whereIn('name', [Role::CITY_ADMIN, Role::BARANGAY_PERSONNEL]));
    }

    public function index(Request $request)
    {
        $query = $this->baseQuery();

        if ($search = trim((string) $request->input('q'))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }
        if ($role = $request->input('role')) {
            $query->whereHas('role', fn ($q) => $q->where('name', $role));
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $users = $query->orderBy('name')->paginate(15)->withQueryString();
        $barangays = Barangay::orderBy('name')->get();
        $roles = Role::whereIn('name', [Role::CITY_ADMIN, Role::BARANGAY_PERSONNEL])->get();

        // PHASE 7 ITEM 6 -- Super Admin accounts, in their own READ-ONLY table.
        //
        // Deliberately not merged into $users and deliberately not editable.
        // authorizeTarget() below already aborts 403 on any target that is not a
        // City Admin or Barangay Personnel, so making this list actionable would
        // mean REMOVING an existing safety rail, not adding a feature -- and it
        // would need a last-active-super-admin guard, a self-demotion guard and
        // a role-change guard before it was safe to ship.
        //
        // Top-level accounts are provisioned by SuperAdminSeeder at deployment.
        // Not paginated: there are one or two of these, and a paginator sharing
        // the page with $users would fight it for the ?page query parameter.
        $superAdmins = User::with('role')
            ->whereHas('role', fn ($q) => $q->where('name', Role::SUPER_ADMIN))
            ->orderBy('name')
            ->get();

        // PHASE 7 ITEM 5. EVERY pending request, because Super Admin can act on
        // every account that can raise one.
        //
        // This list is deliberately the same set as authorizeTarget() below.
        // It used to be City Admin requests only, which was an inconsistency I
        // shipped: Super Admin has always been able to edit a barangay account
        // from the table further down this page, so they could reset a barangay
        // password but never saw the request asking for one. A queue that shows
        // less than you are allowed to act on is a queue that hides work.
        //
        // It also removes a single point of failure. Barangay requests routed
        // only to City Admin means a barangay operator locked out while the City
        // Admin is unreachable -- or while the City Admin is the one locked out
        // -- has nobody. Super Admin is the fallback, which is what a top-level
        // account is for.
        //
        // Overlap is safe: whoever sets the password first closes the request,
        // and it disappears from both screens at once.
        $resetRequests = PasswordResetRequest::with('user.role')
            ->pending()
            ->forRoles([Role::CITY_ADMIN, Role::BARANGAY_PERSONNEL])
            ->orderBy('created_at')
            ->get();

        return view('superadmin.users.index',
            compact('users', 'barangays', 'roles', 'superAdmins', 'resetRequests'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'role' => ['required', Rule::in([Role::CITY_ADMIN, Role::BARANGAY_PERSONNEL])],
            'barangay_id' => ['nullable', 'required_if:role,barangay_personnel', 'exists:barangays,id'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $role = Role::where('name', $data['role'])->firstOrFail();

        $user = User::create([
            'role_id' => $role->id,
            // City Admin isn't tied to a barangay; Barangay Personnel is.
            'barangay_id' => $data['role'] === Role::BARANGAY_PERSONNEL ? $data['barangay_id'] : null,
            'name' => $data['name'],
            'email' => $data['email'],
            'contact_number' => $data['contact_number'] ?? null,
            'password' => Hash::make($data['password']),
            'status' => 'active',
        ]);

        AuditLogger::log('created', $user, "Created {$role->display_name} account: {$user->name}");

        return back()->with('success', "Account for {$user->name} created.");
    }

    public function update(Request $request, User $user)
    {
        $this->authorizeTarget($user);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'role' => ['required', Rule::in([Role::CITY_ADMIN, Role::BARANGAY_PERSONNEL])],
            'barangay_id' => ['nullable', 'required_if:role,barangay_personnel', 'exists:barangays,id'],
            'status' => ['required', 'in:active,inactive'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        $role = Role::where('name', $data['role'])->firstOrFail();
        $roleChanged = $user->role_id !== $role->id;

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'contact_number' => $data['contact_number'] ?? null,
            'role_id' => $role->id,
            'barangay_id' => $data['role'] === Role::BARANGAY_PERSONNEL ? $data['barangay_id'] : null,
            'status' => $data['status'],
        ]);
        $passwordChanged = ! empty($data['password']);
        if ($passwordChanged) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        // PHASE 7 ITEMS 4 AND 5 -- see the same block in the City Admin
        // controller. Setting a password IS the resolution, so it closes the
        // queue entry and clears the lock rather than leaving two more buttons
        // for an administrator to remember.
        if ($passwordChanged) {
            $this->resolveResetRequests($user);
        }

        $desc = $roleChanged
            ? "Changed {$user->name}'s role to {$role->display_name}"
            : "Updated account {$user->name}";
        AuditLogger::log('updated', $user, $desc);

        return back()->with('success', $roleChanged ? "{$user->name} is now {$role->display_name}." : "Account updated.");
    }

    public function toggleStatus(User $user)
    {
        $this->authorizeTarget($user);
        $user->update(['status' => $user->status === 'active' ? 'inactive' : 'active']);
        AuditLogger::log('updated', $user, "Set {$user->name} to {$user->status}");

        return back()->with('success', "{$user->name} is now {$user->status}.");
    }

    /** Super Admin may manage city_admin + barangay_personnel, never other super admins. */
    /** PHASE 7 ITEM 4 -- release a lock early. Locked is not the same as
     *  inactive, so this is not toggleStatus(). */
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

    /** PHASE 7 ITEM 5 -- close a request without touching any credential. */
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

    /**
     * PHASE 7 ITEM 6, deferred half -- a Super Admin changing their OWN password.
     *
     * The one credential action available on a Super Admin account from inside
     * the application, and it is scoped to auth()->user() with no route
     * parameter at all. There is no id to tamper with, so no guard is needed to
     * stop one Super Admin resetting another's password: the route cannot
     * express it.
     *
     * current_password is required. Without it, an unattended signed-in session
     * is a permanent takeover of the highest account in the system.
     *
     * This closes a real gap rather than adding a nicety: before it, a Super
     * Admin who forgot their password had NO in-app recovery -- barangay staff
     * go to City Admin, City Admin goes to Super Admin, and Super Admin went to
     * a seeder or tinker.
     */
    public function updateOwnPassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'current_password.current_password' => 'That is not your current password.',
            'password.confirmed' => 'The two new passwords do not match.',
        ]);

        $user = $request->user();
        $user->password = Hash::make($request->input('password'));
        $user->save();

        AuditLogger::log('updated', $user, 'Changed own Super Admin password');

        return back()->with('success', 'Your password has been changed.');
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
        abort_if(
            ! in_array($user->role?->name, [Role::CITY_ADMIN, Role::BARANGAY_PERSONNEL], true),
            403,
            'You cannot manage this account.'
        );
    }
}
