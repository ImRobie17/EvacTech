<!DOCTYPE html>
<html lang="en" data-theme="{{ request()->cookie('theme', 'light') }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'City Admin') — EvacTech</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,400&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="staff-body">
<div class="staff-shell">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <span class="brand-mark" aria-hidden="true">+</span>
            <span class="brand-name"><span class="brand-evac">Evac</span><span class="brand-tech">Tech</span></span>
        </div>

        <div class="sidebar-user">
            <span class="sidebar-user-role">City Admin</span>
            <span class="sidebar-user-name">{{ auth()->user()->name }}</span>
        </div>

        <nav class="sidebar-nav" aria-label="Main navigation">
            <a href="{{ route('city.dashboard') }}" class="nav-item {{ request()->routeIs('city.dashboard') ? 'active' : '' }}">
                <span class="nav-icon" aria-hidden="true"></span><span class="nav-label">Dashboard</span>
            </a>
            <a href="{{ route('city.shelters.index') }}" class="nav-item {{ request()->routeIs('city.shelters.*') ? 'active' : '' }}">
                <span class="nav-icon" aria-hidden="true"></span><span class="nav-label">Evacuation Shelters</span>
            </a>
            <a href="{{ route('city.evacuees.index') }}" class="nav-item {{ request()->routeIs('city.evacuees.*') ? 'active' : '' }}">
                <span class="nav-icon" aria-hidden="true"></span><span class="nav-label">Evacuee Profiling</span>
            </a>
            <a href="{{ route('city.relief.index') }}" class="nav-item {{ request()->routeIs('city.relief.*') ? 'active' : '' }}">
                <span class="nav-icon" aria-hidden="true"></span><span class="nav-label">Relief Distribution</span>
            </a>
            <a href="{{ route('city.reports.index') }}" class="nav-item {{ request()->routeIs('city.reports.*') ? 'active' : '' }}">
                <span class="nav-icon" aria-hidden="true"></span><span class="nav-label">Report Generation</span>
            </a>
            <a href="{{ route('city.users.index') }}" class="nav-item {{ request()->routeIs('city.users.*') ? 'active' : '' }}">
                <span class="nav-icon" aria-hidden="true"></span><span class="nav-label">User Management</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-controls">
                <button type="button" class="icon-btn" id="themeToggle" aria-label="Toggle dark mode">&#9680;</button>
                <button type="button" class="icon-btn" id="collapseToggle" aria-label="Collapse sidebar">&lt;</button>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="signout-btn"><span aria-hidden="true">&#9099;</span><span class="nav-label"> Sign out</span></button>
            </form>
        </div>
    </aside>

    <main class="staff-main">
        <header class="page-head">
            <div>
                <h1 class="page-title">@yield('page-title')</h1>
                <p class="page-subtitle">@yield('page-subtitle')</p>
            </div>
            <div class="page-head-right">@yield('page-actions')</div>
        </header>

        @if (session('success'))
            <div class="alert alert-success" role="status">&check; {{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
        @endif

        @yield('content')
    </main>
</div>

@stack('modals')
@stack('scripts')
</body>
</html>
