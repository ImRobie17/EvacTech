<?php

namespace App\Http\Controllers\CityAdmin;

use App\Http\Controllers\Controller;
use App\Models\EvacuationCenter;
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

        return view('cityadmin.users.index', compact('users', 'shelters'));
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
            "Created barangay personnel account for {$user->name} (" . count($data['shelters']) . ' shelter assignment(s))');

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
            if (! empty($data['password'])) {
                $user->password = Hash::make($data['password']);
            }
            $user->save();

            $this->syncShelters($user, $data['shelters']);
        });

        AuditLogger::log('updated', $user,
            "Updated barangay personnel account {$user->name}; shelters: " . $user->assignedCenters()->pluck('name')->implode(', '));

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
    private function authorizeTarget(User $user): void
    {
        abort_if($user->role?->name !== Role::BARANGAY_PERSONNEL, 403,
            'You can only manage barangay personnel accounts.');
    }
}
