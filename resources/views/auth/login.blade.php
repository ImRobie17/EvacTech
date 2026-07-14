<!DOCTYPE html>
<html lang="en" data-theme="{{ request()->cookie('theme', 'light') }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Login — EvacTech</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body class="login-body">
    <main class="login-card" aria-labelledby="login-title">
        <div class="login-brand">
            <span class="brand-mark" aria-hidden="true">+</span>
            <span class="brand-name"><span class="brand-evac">Evac</span><span class="brand-tech">Tech</span></span>
        </div>
        <h1 id="login-title" class="login-title">Staff Sign In</h1>
        <p class="login-subtitle">Evacuation management portal for the City of Cabuyao.</p>

        @if (cache()->get('evactech_maintenance', false))
            <div class="alert alert-warning" role="status">
                <strong>System under maintenance.</strong> Only system administrators can sign in right now. Please try again later.
            </div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('login.attempt') }}" class="login-form">
            @csrf
            <div class="field">
                <label for="email">Email address</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>
            <label class="checkbox-row">
                <input type="checkbox" name="remember" value="1"> Keep me signed in
            </label>
            <button type="submit" class="btn-primary btn-block">Sign in</button>
        </form>
    </main>
</body>
</html>
