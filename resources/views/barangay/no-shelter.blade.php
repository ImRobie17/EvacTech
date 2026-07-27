<!DOCTYPE html>
{{--
    Rendered by EnsureShelterAssignment middleware for staff whose shelter
    roster is empty. Standalone document -- it does NOT extend a layout, which
    makes it easy to miss in a layout-first conversion.
--}}
<html lang="en" data-theme="{{ request()->cookie('theme', 'light') }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>No Shelter Assigned &mdash; EvacTech</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="staff-body">
<main class="blocked-shell">
    <div class="card panel blocked-panel" role="alert">
        <span class="blocked-mark" aria-hidden="true">&#9888;</span>
        <h1>No evacuation shelter assigned</h1>
        <p>
            Your account, <strong>{{ auth()->user()->name }}</strong>, is active but has not been
            assigned to any evacuation shelter yet. Shelter operations stay locked until an
            assignment is in place.
        </p>
        <p class="blocked-action">
            Please contact your <strong>Evacuation Administrator</strong> to have one or more
            shelters assigned to your account, then sign in again.
        </p>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="btn-secondary">Sign out</button>
        </form>
    </div>
</main>
</body>
</html>
