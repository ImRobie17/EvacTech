@extends('layouts.public')

@section('title', 'Find Family')

@section('content')
<section class="public-hero">
    <h1>Find Family</h1>
    <p>Check whether a family member or friend has checked in at an evacuation center. Enter their exact registered name.</p>
</section>

<section class="find-family-section">
    <div class="card panel find-form-card">
        <form method="POST" action="{{ route('public.find-family.search') }}">
            @csrf
            <div class="find-grid">
                <div class="field">
                    <label for="ff-last">Last name</label>
                    <input type="text" id="ff-last" name="last_name" value="{{ old('last_name') }}" required maxlength="100" autocomplete="family-name">
                </div>
                <div class="field">
                    <label for="ff-first">First name</label>
                    <input type="text" id="ff-first" name="first_name" value="{{ old('first_name') }}" required maxlength="100" autocomplete="given-name">
                </div>
                <div class="field">
                    <label for="ff-middle">Middle name <small>(optional)</small></label>
                    <input type="text" id="ff-middle" name="middle_name" value="{{ old('middle_name') }}" maxlength="100" autocomplete="additional-name">
                </div>
            </div>
            @if ($errors->any())
                <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
            @endif
            <button type="submit" class="btn-primary">Search</button>
        </form>
        <p class="privacy-note">Only exact name matches are shown, and results never include which shelter a person is in. This protects evacuees from being located by people they may not want to be found by.</p>
    </div>

    @isset($results)
        <div class="card panel results-card">
            <h2 class="panel-title">Results for "{{ $searched }}"</h2>
            @forelse($results as $r)
                @php
                    $statusInfo = [
                        'checked_in' => ['label' => 'Checked in at an evacuation center', 'class' => 'badge-success'],
                        'checked_out' => ['label' => 'Checked out of an evacuation center', 'class' => 'badge-warning'],
                        'not_present' => ['label' => 'Registered, but not marked present at a center', 'class' => 'badge-info'],
                        'registered' => ['label' => 'Registered in the system', 'class' => 'badge-info'],
                        'unknown' => ['label' => 'Status unavailable', 'class' => 'badge-info'],
                    ][$r['status']];
                @endphp
                <div class="result-row">
                    <span class="result-name">{{ $r['name'] }}</span>
                    <span class="badge {{ $statusInfo['class'] }}">{{ $statusInfo['label'] }}</span>
                    @if($r['at'])
                        <span class="result-time" data-numeric>{{ $r['status'] === 'checked_in' ? 'Since' : 'On' }} {{ $r['at'] }}</span>
                    @endif
                </div>
            @empty
                <p class="empty-note"><strong>Not found.</strong> No exact match for that name. Double-check the spelling (the name must match exactly as registered), or the person may not be registered in any evacuation center.</p>
            @endforelse
        </div>

        <div class="card panel verify-card">
            <h2 class="panel-title">Need to know which shelter they are in?</h2>
            <p>For the safety and privacy of evacuees, shelter locations are not shown publicly. If you are an immediate family member, contact the CDRRMO so staff can verify your identity and assist you:</p>
            <a href="{{ route('public.hotlines') }}" class="btn-secondary">View Emergency Hotlines</a>
        </div>
    @endisset
</section>
@endsection
