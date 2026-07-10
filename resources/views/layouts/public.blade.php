<!DOCTYPE html>
<html lang="en" data-theme="{{ request()->cookie('theme', 'light') }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'EvacTech') - City of Cabuyao</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="public-body">
<header class="public-header">
    <div class="public-header-inner">
        <a href="{{ route('public.map') }}" class="public-brand">
            <span class="brand-mark" aria-hidden="true">+</span>
            <span class="brand-name"><span class="brand-evac">Evac</span><span class="brand-tech">Tech</span></span>
            <span class="public-brand-sub">City of Cabuyao</span>
        </a>

        <nav class="public-nav" aria-label="Main navigation">
            <a href="{{ route('public.map') }}" class="public-nav-item {{ request()->routeIs('public.map') ? 'active' : '' }}">Evacuation Map</a>
            <a href="{{ route('public.find-family') }}" class="public-nav-item {{ request()->routeIs('public.find-family*') ? 'active' : '' }}">Find Family</a>
            <a href="{{ route('public.hotlines') }}" class="public-nav-item {{ request()->routeIs('public.hotlines') ? 'active' : '' }}">Emergency Hotlines</a>
        </nav>

        <div class="public-header-right">
            <button type="button" class="icon-btn" id="themeToggle" aria-label="Toggle dark mode">&#9680;</button>
            <a href="{{ route('login') }}" class="staff-login-link">Staff Login</a>
        </div>
    </div>
</header>

<main class="public-main">
    @yield('content')
</main>

<footer class="public-footer">
    <p>EvacTech - Evacuation Management System for the City of Cabuyao, Laguna.</p>
    <p class="text-muted">In a life-threatening emergency, call 911 immediately.</p>
</footer>

@stack('scripts')
</body>
</html>
