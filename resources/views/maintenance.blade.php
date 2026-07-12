<!DOCTYPE html>
<html lang="en" data-theme="{{ request()->cookie('theme', 'light') }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Under Maintenance - EvacTech</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css'])
</head>
<body class="login-body">
    <main class="login-card" style="text-align:center;">
        <div class="login-brand" style="justify-content:center;">
            <span class="brand-mark" aria-hidden="true">+</span>
            <span class="brand-name"><span class="brand-evac">Evac</span><span class="brand-tech">Tech</span></span>
        </div>
        <h1 class="login-title">Under Maintenance</h1>
        <p class="login-subtitle">The system is temporarily unavailable while our administrators perform maintenance. Please check back shortly.</p>
        <p class="text-muted">In an emergency, call the CDRRMO hotline or 911.</p>
    </main>
</body>
</html>
