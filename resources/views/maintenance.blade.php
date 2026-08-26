<!DOCTYPE html>
{{--
    Maintenance screen, rendered by HandleMaintenance middleware.
    Standalone document -- it does not extend a layout.
    Inline style attributes replaced by .maintenance-card / .login-brand, which
    already centre their contents.
--}}
<html lang="en" data-theme="{{ request()->cookie('theme', 'light') }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Under Maintenance &mdash; EvacTech</title>
    @vite(['resources/css/app.css'])
</head>
<body class="login-body">
    <main class="login-card maintenance-card">
        <div class="login-brand">
            <span class="brand-mark" aria-hidden="true">&#10010;</span>
            <span class="brand-name"><span class="brand-evac">Evac</span><span class="brand-tech">Tech</span></span>
        </div>
        <h1 class="login-title">Under Maintenance</h1>
        <p class="login-subtitle">The system is temporarily unavailable while our administrators perform maintenance. Please check back shortly.</p>
        <p class="text-muted">In an emergency, call the CDRRMO hotline or 911.</p>
    </main>
</body>
</html>
