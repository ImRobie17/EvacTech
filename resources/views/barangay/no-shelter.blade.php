<!DOCTYPE html>
<html lang="en" data-theme="{{ request()->cookie('theme', 'light') }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>No Shelter Assigned &mdash; EvacTech</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
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
