<?php

namespace App\Http\Controllers\CityAdmin;

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
    /** Barangay Personnel accounts only -- City Admin cannot see other admins. */
    private function baseQuery()
    {
        return User::with(['barangay', 'role'])
            ->whereHas('role', fn ($q) => $q->where('name', Role::BARANGAY_PERSONNEL));
    }

    public function index(Request $request)
    {
        $query = $this->baseQuery();

        if ($search = trim((string) $request->input('q'))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }
        if ($barangay = $request->input('barangay')) {
            $query->where('barangay_id', $barangay);
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $users = $query->orderBy('name')->paginate(15)->withQueryString();
        $barangays = Barangay::orderBy('name')->get();

        return view('cityadmin.users.index', compact('users', 'barangays'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email'],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'barangay_id' => ['required', 'exists:barangays,id'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $role = Role::where('name', Role::BARANGAY_PERSONNEL)->firstOrFail();

        $user = User::create([
            'role_id' => $role->id,
            'barangay_id' => $data['barangay_id'],
            'name' => $data['name'],
            'email' => $data['email'],
            'contact_number' => $data['contact_number'] ?? null,
            'password' => Hash::make($data['password']),
            'status' => 'active',
        ]);

        AuditLogger::log('created', $user, "Created barangay personnel account for {$user->name}");

        return back()->with('success', "Account for {$user->name} created.");
    }

    public function update(Request $request, User $user)
    {
        $this->authorizeTarget($user);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'contact_number' => ['nullable', 'string', 'max:20'],
            'barangay_id' => ['required', 'exists:barangays,id'], // reassignment
            'status' => ['required', 'in:active,inactive'],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        $user->fill([
            'name' => $data['name'],
            'email' => $data['email'],
            'contact_number' => $data['contact_number'] ?? null,
            'barangay_id' => $data['barangay_id'],
            'status' => $data['status'],
        ]);
        if (! empty($data['password'])) {
            $user->password = Hash::make($data['password']);
        }
        $user->save();

        AuditLogger::log('updated', $user, "Updated barangay personnel account {$user->name}");

        return back()->with('success', "Account for {$user->name} updated.");
    }

    public function toggleStatus(User $user)
    {
        $this->authorizeTarget($user);
        $user->update(['status' => $user->status === 'active' ? 'inactive' : 'active']);

        AuditLogger::log('updated', $user, "Set {$user->name} to {$user->status}");

        return back()->with('success', "{$user->name} is now {$user->status}.");
    }

    /** Guard: City Admin may only ever act on barangay personnel rows. */
    private function authorizeTarget(User $user): void
    {
        abort_if($user->role?->name !== Role::BARANGAY_PERSONNEL, 403, 'You can only manage barangay personnel accounts.');
    }
}
