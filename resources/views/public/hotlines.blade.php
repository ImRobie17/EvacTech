@extends('layouts.public')

@section('title', 'Emergency Hotlines')

@section('content')
<section class="public-hero">
    <h1>Emergency Hotlines</h1>
    <p>Important contact numbers for the City of Cabuyao. In a life-threatening emergency, call <strong>911</strong> immediately.</p>
</section>

<section class="hotlines-section">
    @foreach($groups as $category => $hotlines)
        <div class="hotline-group">
            <h2 class="hotline-category">{{ \App\Models\EmergencyHotline::categoryLabel($category) }}</h2>
            <div class="hotline-cards">
                @foreach($hotlines as $h)
                    <article class="card hotline-card">
                        <h3>{{ $h->label }}</h3>
                        <a class="hotline-number" href="tel:{{ preg_replace('/[^0-9+]/', '', $h->number) }}" data-numeric>{{ $h->number }}</a>
                        @if($h->description)
                            <p class="hotline-desc">{{ $h->description }}</p>
                        @endif
                    </article>
                @endforeach
            </div>
        </div>
    @endforeach

    @if($groups->isEmpty())
        <p class="empty-note">Hotline directory is being updated. In an emergency, call 911.</p>
    @endif
</section>
@endsection
