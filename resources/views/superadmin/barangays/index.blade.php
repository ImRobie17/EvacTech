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
                    <th style="min-width: 200px;">Name</th>
                    <th style="min-width: 100px;">Code</th>
                    <th style="min-width: 120px;">Risk Level</th>
                    <th style="min-width: 80px; text-align: center;">Users</th>
                    <th style="min-width: 140px; text-align: center;">Evacuation Centers</th>
                    <th style="min-width: 100px; text-align: center;">Households</th>
                    <th style="min-width: 180px;">Coordinates</th>
                    <th style="min-width: 160px; text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($barangays as $barangay)
                    <tr>
                        <td>{{ $barangay->name }}</td>
                        <td>{{ $barangay->code }}</td>
                        <td>{{ $barangay->risk_level ? ucfirst($barangay->risk_level) : '-' }}</td>
                        <td style="text-align: center;">{{ $barangay->users_count }}</td>
                        <td style="text-align: center;">{{ $barangay->evacuation_centers_count }}</td>
                        <td style="text-align: center;">{{ $barangay->households_count }}</td>
                        <td>
                            @if($barangay->latitude && $barangay->longitude)
                                {{ $barangay->latitude }}, {{ $barangay->longitude }}
                            @else
                                -
                            @endif
                        </td>
                        <td style="text-align: center;">
                            <div class="flex gap-2 justify-center">
                                <a href="{{ route('super.barangays.edit', $barangay) }}"
                                   class="btn-sm btn-secondary whitespace-nowrap">
                                    Edit
                                </a>
                                <form method="POST" action="{{ route('super.barangays.destroy', $barangay) }}"
                                      class="inline"
                                      onsubmit="return confirm('Are you sure you want to delete barangay {{ $barangay->name }}? This action cannot be undone.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-sm btn-danger whitespace-nowrap">
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
