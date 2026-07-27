@extends('layouts.public')

@section('title', 'Emergency Hotlines')

{{--
    Converted to Tailwind (roadmap item 2).

    The public views are converted THOROUGHLY rather than partially, because
    public.css is used by these three views alone -- nothing here is shared with
    an unconverted role. Only .card and .badge-* survive as classes, since those
    are shared primitives defined in staff.css.

    Tailwind's spacing scale maps 1:1 onto the old --space-N tokens (both are
    0.25rem steps), so gap-3 is exactly the var(--space-3) it replaces. At the
    17px root that is 12.75px, not 12px -- deliberate, see app.css.
--}}

@section('content')
<section class="mb-6">
    <h1 class="mb-2 text-xl md:text-2xl">Emergency Hotlines</h1>
    <p class="m-0 max-w-[640px] text-ink-soft">
        Important contact numbers for the City of Cabuyao. In a life-threatening emergency, call <strong>911</strong> immediately.
    </p>
</section>

<section>
    @foreach($groups as $category => $hotlines)
        <div class="mb-8">
            <h2 class="mb-4 inline-block border-b-2 border-primary pb-1 text-lg sm:text-xl">
                {{ \App\Models\EmergencyHotline::categoryLabel($category) }}
            </h2>

            {{-- Explicit column counts rather than auto-fill minmax(): at 380px a
                 single column is the only sane layout, and predictable steps are
                 easier to test than a fluid track that can produce a lone
                 stretched card. --}}
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 xl:grid-cols-3">
                @foreach($hotlines as $h)
                    <article class="card p-4 sm:p-5">
                        <h3 class="mb-2 text-base">{{ $h->label }}</h3>
                        {{-- The number is the whole point of this page and a tel:
                             link is the fastest useful action on a phone, so it
                             gets a full 44px target, not inline link sizing. --}}
                        <a class="inline-flex min-h-tap items-center font-mono text-xl font-semibold text-primary no-underline hover:underline"
                           href="tel:{{ preg_replace('/[^0-9+]/', '', $h->number) }}" data-numeric>{{ $h->number }}</a>
                        @if($h->description)
                            <p class="mt-2 mb-0 text-sm text-ink-muted">{{ $h->description }}</p>
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
