<!DOCTYPE html>
{{--
    PHASE 7 ITEM 5 -- request a password reset.

    Standalone document, mirroring auth/login. It does not extend a layout and
    loads no JavaScript: every staff layout assumes a signed-in user, and the
    person reaching this page is by definition not one.

    Pure ASCII throughout. Entities (&mdash;, &hellip;) in raw HTML only, never
    inside an escaped Blade echo, where e() would double-encode them.
--}}
<html lang="en" data-theme="{{ request()->cookie('theme', 'light') }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Request Password Reset &mdash; EvacTech</title>
    @vite(['resources/css/app.css'])
</head>
<body class="login-body">
    <main class="login-card" aria-labelledby="request-title">
        <div class="login-brand">
            <span class="brand-mark" aria-hidden="true">&#10010;</span>
            <span class="brand-name"><span class="brand-evac">Evac</span><span class="brand-tech">Tech</span></span>
        </div>
        <h1 id="request-title" class="login-title">Request Password Reset</h1>
        <p class="login-subtitle">
            Your administrator will call you to confirm this request before setting a new password.
            No password is sent by email.
        </p>

        @if ($errors->any())
            <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('password.request.store') }}" class="login-form">
            @csrf
            <div class="field">
                <label for="email">Email address you sign in with</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username">
            </div>
            {{-- Separate from the number on file on purpose: the number the
                 account holds may be the one they have lost access to. --}}
            <div class="field">
                <label for="contact_number">Contact number <small>(optional)</small></label>
                <input type="text" id="contact_number" name="contact_number" value="{{ old('contact_number') }}" maxlength="20"
                       autocomplete="tel" placeholder="A number you can be reached on today">
            </div>
            <button type="submit" class="btn-primary btn-block">Send request</button>
        </form>

        <p class="login-subtitle">
            <a href="{{ route('login') }}" class="btn-link">Back to sign in</a>
        </p>
    </main>
</body>
</html>
