<?php

namespace App\Http\Controllers\CityAdmin;

use App\Http\Controllers\Controller;
use App\Models\Barangay;
use App\Models\EvacuationCenter;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class ShelterController extends Controller
{
    public function index(Request $request)
    {
        $query = EvacuationCenter::with(['barangay', 'manager'])
            ->withCount(['households as checked_in_count' => fn ($q) => $q->where('status', 'checked_in')]);

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

        return view('cityadmin.shelters.index', compact('centers', 'barangays'));
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
            'managed_by' => ['nullable', 'exists:users,id'],
            'has_water_supply' => ['nullable', 'boolean'],
            'has_medical_desk' => ['nullable', 'boolean'],
            'has_power' => ['nullable', 'boolean'],
            'has_communal_kitchen' => ['nullable', 'boolean'],
        ]);

        $center = EvacuationCenter::create([
            ...$data,
            'current_occupancy' => 0,
            'status' => 'active',
            'has_water_supply' => $request->boolean('has_water_supply'),
            'has_medical_desk' => $request->boolean('has_medical_desk'),
            'has_power' => $request->boolean('has_power'),
            'has_communal_kitchen' => $request->boolean('has_communal_kitchen'),
            'created_by' => auth()->id(),
        ]);

        AuditLogger::log('created', $center, "Added evacuation center {$center->name}");

        return redirect()->route('city.shelters.index')->with('success', "Evacuation center \"{$center->name}\" added.");
    }

    public function update(Request $request, EvacuationCenter $center)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:255'],
            'capacity' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'in:active,inactive,full'],
            'managed_by' => ['nullable', 'exists:users,id'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'has_water_supply' => ['nullable', 'boolean'],
            'has_medical_desk' => ['nullable', 'boolean'],
            'has_power' => ['nullable', 'boolean'],
            'has_communal_kitchen' => ['nullable', 'boolean'],
        ]);

        $center->update([
            ...$data,
            'has_water_supply' => $request->boolean('has_water_supply'),
            'has_medical_desk' => $request->boolean('has_medical_desk'),
            'has_power' => $request->boolean('has_power'),
            'has_communal_kitchen' => $request->boolean('has_communal_kitchen'),
        ]);

        AuditLogger::log('updated', $center, "Updated evacuation center {$center->name}");

        return back()->with('success', 'Evacuation center updated.');
    }

    /** Barangay personnel available to manage a center, for the assignment dropdown. */
    public function managersForBarangay(Barangay $barangay)
    {
        $users = User::whereHas('role', fn ($q) => $q->where('name', 'barangay_personnel'))
            ->where('barangay_id', $barangay->id)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json($users);
    }
}
