<!DOCTYPE html>
<html lang="en" data-theme="{{ request()->cookie('theme', 'light') }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Login — EvacTech</title>
    @vite(['resources/css/app.css'])
</head>
<body class="login-body">
    <main class="login-card" aria-labelledby="login-title">
        <div class="login-brand">
            <span class="brand-mark" aria-hidden="true">✚</span>
            <span class="brand-name"><span class="brand-evac">Evac</span><span class="brand-tech">Tech</span></span>
        </div>
        <h1 id="login-title" class="login-title">Staff Sign In</h1>
        <p class="login-subtitle">Evacuation management portal for the City of Cabuyao.</p>

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
