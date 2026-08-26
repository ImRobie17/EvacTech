@extends('layouts.superadmin')

@section('title', 'Barangay Management')
@section('page-title', 'Barangay Management')
@section('page-subtitle', 'Manage barangays within Cabuyao City')

@section('content')
<div class="card panel mb-4">
    <div class="flex items-center justify-between mb-4">
        <h2 class="panel-title">All Barangays</h2>
        <a href="{{ route('super.barangays.create') }}" class="btn-primary">
            + Add New Barangay
        </a>
    </div>

    @if ($errors->any())
        <div class="alert alert-error mb-4">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="overflow-x-auto">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Code</th>
                    <th>Risk Level</th>
                    <th>Users</th>
                    <th>Evacuation Centers</th>
                    <th>Households</th>
                    <th>Coordinates</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($barangays as $barangay)
                    <tr>
                        <td class="text-truncate">{{ $barangay->name }}</td>
                        <td class="text-truncate-sm">{{ $barangay->code }}</td>
                        <td>{{ $barangay->risk_level ?? '-' }}</td>
                        <td>{{ $barangay->users_count }}</td>
                        <td>{{ $barangay->evacuation_centers_count }}</td>
                        <td>{{ $barangay->households_count }}</td>
                        <td class="text-truncate-sm">
                            @if($barangay->latitude && $barangay->longitude)
                                {{ $barangay->latitude }}, {{ $barangay->longitude }}
                            @else
                                -
                            @endif
                        </td>
                        <td class="actions-cell">
                            <div class="flex gap-2">
                                <a href="{{ route('super.barangays.edit', $barangay) }}"
                                   class="btn-sm btn-secondary">
                                    Edit
                                </a>
                                <form method="POST" action="{{ route('super.barangays.destroy', $barangay) }}"
                                      class="inline"
                                      onsubmit="return confirm('Are you sure you want to delete barangay {{ $barangay->name }}? This action cannot be undone.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-sm btn-danger">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="text-center py-8">
                            <p class="empty-note">No barangays found.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
