<?php

namespace App\Http\Controllers\CityAdmin;

use App\Http\Controllers\Controller;
use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ShelterController extends Controller
{
    public function index(Request $request)
    {
        $query = EvacuationCenter::with(['barangay', 'assignedStaff'])
            ->withCount([
                'households as checked_in_count' => fn ($q) => $q->where('status', 'checked_in'),
                'assignedStaff as staff_count',
            ]);

        if ($search = trim((string) $request->input('q'))) {
            $query->where('name', 'like', "%{$search}%");
        }
        if ($barangay = $request->input('barangay')) {
            $query->where('barangay_id', $barangay);
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $centers = $query->orderBy('name')->paginate(15)->withQueryString();
        $barangays = Barangay::orderBy('name')->get();

        // Full roster pool for the add/edit modal. Barangay personnel are no
        // longer restricted to shelters in their own barangay -- surge staffing
        // across barangays is normal during a real event -- so the whole active
        // pool is offered, grouped by their nominal barangay for orientation.
        $staffPool = $this->staffPool();

        return view('cityadmin.shelters.index', compact('centers', 'barangays', 'staffPool'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'barangay_id' => ['required', 'exists:barangays,id'],
            'address' => ['required', 'string', 'max:255'],
            'capacity' => ['required', 'integer', 'min:1'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'staff' => ['nullable', 'array'],
            'staff.*' => ['integer', 'exists:users,id'],
            'has_water_supply' => ['nullable', 'boolean'],
            'has_medical_desk' => ['nullable', 'boolean'],
            'has_power' => ['nullable', 'boolean'],
            'has_communal_kitchen' => ['nullable', 'boolean'],
        ]);

        $center = DB::transaction(function () use ($request, $data) {
            $center = EvacuationCenter::create([
                'name' => $data['name'],
                'barangay_id' => $data['barangay_id'],
                'address' => $data['address'],
                'capacity' => $data['capacity'],
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'current_occupancy' => 0,
                'status' => 'active',
                'has_water_supply' => $request->boolean('has_water_supply'),
                'has_medical_desk' => $request->boolean('has_medical_desk'),
                'has_power' => $request->boolean('has_power'),
                'has_communal_kitchen' => $request->boolean('has_communal_kitchen'),
                'created_by' => auth()->id(),
            ]);

            $this->syncStaff($center, $data['staff'] ?? []);

            return $center;
        });

        AuditLogger::log('created', $center,
            "Added evacuation center {$center->name} with " . count($data['staff'] ?? []) . ' assigned staff');

        return redirect()->route('city.shelters.index')
            ->with('success', "Evacuation center \"{$center->name}\" added.");
    }

    public function update(Request $request, EvacuationCenter $center)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'barangay_id' => ['required', 'exists:barangays,id'],
            'address' => ['required', 'string', 'max:255'],
            'capacity' => ['required', 'integer', 'min:1'],
            // 'full' is gone: overcapacity is derived from occupancy and never
            // changes the operational status.
            'status' => ['required', 'in:active,inactive'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'staff' => ['nullable', 'array'],
            'staff.*' => ['integer', 'exists:users,id'],
            'has_water_supply' => ['nullable', 'boolean'],
            'has_medical_desk' => ['nullable', 'boolean'],
            'has_power' => ['nullable', 'boolean'],
            'has_communal_kitchen' => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($request, $data, $center) {
            $center->update([
                'name' => $data['name'],
                'barangay_id' => $data['barangay_id'],
                'address' => $data['address'],
                'capacity' => $data['capacity'],
                'status' => $data['status'],
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'has_water_supply' => $request->boolean('has_water_supply'),
                'has_medical_desk' => $request->boolean('has_medical_desk'),
                'has_power' => $request->boolean('has_power'),
                'has_communal_kitchen' => $request->boolean('has_communal_kitchen'),
            ]);

            $this->syncStaff($center, $data['staff'] ?? []);
            $center->recalcOccupancy();
        });

        AuditLogger::log('updated', $center, "Updated evacuation center {$center->name}");

        return back()->with('success', 'Evacuation center updated.');
    }

    /**
     * Barangay personnel available to staff a shelter (JSON).
     * Replaces managersForBarangay(): assignment is a roster, and staff are no
     * longer filtered to a single barangay.
     */
    public function assignableStaff(Request $request)
    {
        return response()->json(
            $this->staffPool()->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'barangay' => $u->barangay?->name,
            ])->values()
        );
    }

    // -----------------------------------------------------------------

    private function staffPool()
    {
        return User::with('barangay')
            ->whereHas('role', fn ($q) => $q->where('name', Role::BARANGAY_PERSONNEL))
            ->where('status', 'active')
            ->orderBy('name')
            ->get();
    }

    /**
     * Replace the shelter's roster. Only barangay personnel may be assigned;
     * anything else in the request is silently dropped rather than trusted.
     */
    private function syncStaff(EvacuationCenter $center, array $userIds): void
    {
        $valid = User::whereIn('id', $userIds)
            ->whereHas('role', fn ($q) => $q->where('name', Role::BARANGAY_PERSONNEL))
            ->pluck('id');

        $center->assignedStaff()->sync(
            $valid->mapWithKeys(fn ($id) => [$id => [
                'assigned_by' => auth()->id(),
                'assigned_at' => now(),
            ]])->all()
        );
    }
}
