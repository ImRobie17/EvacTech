<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Barangay;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BarangayManagementController extends Controller
{
    public function index()
    {
        $barangays = Barangay::withCount([
            'users',
            'evacuationCenters',
            'households'
        ])->orderBy('name')->get();

        return view('superadmin.barangays.index', compact('barangays'));
    }

    public function create()
    {
        return view('superadmin.barangays.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:barangays'],
            'code' => ['required', 'string', 'max:50', 'unique:barangays'],
            'risk_level' => ['nullable', 'string', 'in:low,moderate,high'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        // Normalize risk_level to lowercase to match database ENUM
        if (isset($data['risk_level'])) {
            $data['risk_level'] = strtolower($data['risk_level']);
        }

        $barangay = Barangay::create($data);

        AuditLogger::log('created', $barangay, "Created barangay: {$barangay->name}");

        return redirect()->route('super.barangays.index')
            ->with('success', "Barangay '{$barangay->name}' has been created successfully.");
    }

    public function edit(Barangay $barangay)
    {
        return view('superadmin.barangays.edit', compact('barangay'));
    }

    public function update(Request $request, Barangay $barangay)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('barangays')->ignore($barangay->id)],
            'code' => ['required', 'string', 'max:50', Rule::unique('barangays')->ignore($barangay->id)],
            'risk_level' => ['nullable', 'string', 'in:low,moderate,high'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        // Normalize risk_level to lowercase to match database ENUM
        if (isset($data['risk_level'])) {
            $data['risk_level'] = strtolower($data['risk_level']);
        }

        $oldName = $barangay->name;
        $barangay->update($data);

        AuditLogger::log('updated', $barangay, "Updated barangay: {$oldName} -> {$barangay->name}");

        return redirect()->route('super.barangays.index')
            ->with('success', "Barangay '{$barangay->name}' has been updated successfully.");
    }

    public function destroy(Barangay $barangay)
    {
        // Check for dependencies
        $usersCount = $barangay->users()->count();
        $centersCount = $barangay->evacuationCenters()->count();
        $householdsCount = $barangay->households()->count();

        if ($usersCount > 0 || $centersCount > 0 || $householdsCount > 0) {
            $dependencies = [];
            if ($usersCount > 0) $dependencies[] = "{$usersCount} user(s)";
            if ($centersCount > 0) $dependencies[] = "{$centersCount} evacuation center(s)";
            if ($householdsCount > 0) $dependencies[] = "{$householdsCount} household(s)";

            return back()->withErrors([
                'delete' => "Cannot delete barangay '{$barangay->name}' because it has: " . implode(', ', $dependencies) . '. Please reassign or delete these records first.'
            ]);
        }

        $name = $barangay->name;
        $barangay->delete();

        AuditLogger::log('deleted', $barangay, "Deleted barangay: {$name}");

        return redirect()->route('super.barangays.index')
            ->with('success', "Barangay '{$name}' has been deleted successfully.");
    }
}
