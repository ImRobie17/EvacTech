<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Barangay;
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

        return view('superadmin.users.index', compact('users', 'barangays', 'roles'));
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
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

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
    private function authorizeTarget(User $user): void
    {
        abort_if(
            ! in_array($user->role?->name, [Role::CITY_ADMIN, Role::BARANGAY_PERSONNEL], true),
            403,
            'You cannot manage this account.'
        );
    }
}
