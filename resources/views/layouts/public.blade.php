<!DOCTYPE html>
{{--
    Public Citizen layout. No authentication -- citizens have no login.

    Navigation is never hidden behind a menu button here. On a public safety
    site the three destinations are the whole point of the page, and a citizen
    looking for a relative during a flood should not have to discover a
    hamburger first. The row simply scrolls sideways if it ever overflows.
--}}
<html lang="en" data-theme="{{ request()->cookie('theme', 'light') }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'EvacTech') &mdash; City of Cabuyao</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="public-body">
<header class="public-header">
    <div class="public-header-inner">
        <a href="{{ route('public.map') }}" class="public-brand">
            <span class="brand-mark" aria-hidden="true">&#10010;</span>
            <span class="brand-name"><span class="brand-evac">Evac</span><span class="brand-tech">Tech</span></span>
            <span class="public-brand-sub">City of Cabuyao</span>
        </a>

        <div class="public-header-right">
            <button type="button" class="icon-btn" id="themeToggle" aria-label="Toggle dark mode">&#9680;</button>

            {{-- Inline style attributes removed: the button is styled by
                 .staff-login-link in public.css, which also gives it the 44px
                 tap target the inline version was missing. --}}
            @auth
                <form method="POST" action="{{ route('logout') }}" class="inline-form">
                    @csrf
                    <button type="submit" class="staff-login-link">Logout</button>
                </form>
            @else
                <a href="{{ route('login') }}" class="staff-login-link">Staff Login</a>
            @endauth
        </div>

        <nav class="public-nav" aria-label="Main navigation">
            <a href="{{ route('public.map') }}" class="public-nav-item {{ request()->routeIs('public.map') ? 'active' : '' }}">Evacuation Map</a>
            <a href="{{ route('public.find-family') }}" class="public-nav-item {{ request()->routeIs('public.find-family*') ? 'active' : '' }}">Find Family</a>
            <a href="{{ route('public.hotlines') }}" class="public-nav-item {{ request()->routeIs('public.hotlines') ? 'active' : '' }}">Emergency Hotlines</a>
        </nav>
    </div>
</header>

<main class="public-main">
    @yield('content')
</main>

<footer class="public-footer">
    <p>EvacTech &mdash; Evacuation Management System for the City of Cabuyao, Laguna.</p>
    <p class="text-muted">In a life-threatening emergency, call 911 immediately.</p>
</footer>

@stack('scripts')
</body>
</html>
