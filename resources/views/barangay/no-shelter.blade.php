<!DOCTYPE html>
{{--
    Rendered by EnsureShelterAssignment middleware for staff whose shelter
    roster is empty. Standalone document -- it does NOT extend a layout, which
    is exactly why it was nearly missed in a layout-first conversion.

    Converted to Tailwind (roadmap item 2). The .blocked-* classes it used are
    still defined in staff.css and still reached by nothing else, so they are
    listed as dead code for the Phase 4 cleanup rather than deleted here.
--}}
<html lang="en" data-theme="{{ request()->cookie('theme', 'light') }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>No Shelter Assigned &mdash; EvacTech</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="staff-body">
{{-- min-h-screen with centred content: this is the whole page, and on a phone
     the message must be readable without scrolling to find it. --}}
<main class="flex min-h-screen items-center justify-center p-4">
    <div class="card panel max-w-[34rem] text-center" role="alert">
        <span class="text-3xl text-warning" aria-hidden="true">&#9888;</span>
        <h1 class="mt-3 mb-4 text-xl">No evacuation shelter assigned</h1>
        <p class="mb-4 leading-relaxed text-ink-soft">
            Your account, <strong>{{ auth()->user()->name }}</strong>, is active but has not been
            assigned to any evacuation shelter yet. Shelter operations stay locked until an
            assignment is in place.
        </p>
        <p class="mb-4 rounded-sm bg-warning-bg p-3 leading-relaxed text-ink-soft">
            Please contact your <strong>Evacuation Administrator</strong> to have one or more
            shelters assigned to your account, then sign in again.
        </p>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn-secondary w-full sm:w-auto">Sign out</button>
        </form>
    </div>
</main>
</body>
</html>
