@extends('layouts.superadmin')

@section('title', 'Edit Barangay')
@section('page-title', 'Edit Barangay')
@section('page-subtitle', 'Update barangay details')

@section('content')
<div class="card panel">
    <h2 class="panel-title">Edit Barangay: {{ $barangay->name }}</h2>

    <form method="POST" action="{{ route('super.barangays.update', $barangay) }}">
        @csrf
        @method('PUT')

        @if ($errors->any())
            <div class="alert alert-error mb-4">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="field">
            <label for="name">Barangay Name <span class="text-red-500">*</span></label>
            <input type="text" id="name" name="name" 
                   value="{{ old('name', $barangay->name) }}" 
                   placeholder="e.g. Mamatid" 
                   required>
        </div>

        <div class="field">
            <label for="code">Barangay Code <span class="text-red-500">*</span></label>
            <input type="text" id="code" name="code" 
                   value="{{ old('code', $barangay->code) }}" 
                   placeholder="e.g. MMTD" 
                   required>
            <p class="mt-1 text-xs text-ink-muted">
                Short code for identification (e.g., MMTD for Mamatid)
            </p>
        </div>

        <div class="field">
            <label for="risk_level">Risk Level</label>
            <input type="text" id="risk_level" name="risk_level" 
                   value="{{ old('risk_level', $barangay->risk_level) }}" 
                   placeholder="e.g. High, Medium, Low">
            <p class="mt-1 text-xs text-ink-muted">
                Optional: Risk level classification for disaster response planning
            </p>
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div class="field">
                <label for="latitude">Latitude</label>
                <input type="number" id="latitude" name="latitude" 
                       value="{{ old('latitude', $barangay->latitude) }}" 
                       step="any" 
                       placeholder="e.g. 14.2729"
                       min="-90" max="90">
                <p class="mt-1 text-xs text-ink-muted">
                    Optional: Geographic coordinates for mapping
                </p>
            </div>

            <div class="field">
                <label for="longitude">Longitude</label>
                <input type="number" id="longitude" name="longitude" 
                       value="{{ old('longitude', $barangay->longitude) }}" 
                       step="any" 
                       placeholder="e.g. 121.1258"
                       min="-180" max="180">
                <p class="mt-1 text-xs text-ink-muted">
                    Optional: Geographic coordinates for mapping
                </p>
            </div>
        </div>

        <div class="flex gap-3 mt-6">
            <a href="{{ route('super.barangays.index') }}" class="btn-secondary">
                Cancel
            </a>
            <button type="submit" class="btn-primary">
                Update Barangay
            </button>
        </div>
    </form>
</div>
@endsection
