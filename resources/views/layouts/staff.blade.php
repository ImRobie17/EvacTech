<!DOCTYPE html>
<html lang="en" data-theme="{{ request()->cookie('theme', 'light') }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'EvacTech') — EvacTech</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="staff-body">
<div class="staff-shell">
    {{-- ============ Sidebar ============ --}}
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <span class="brand-mark" aria-hidden="true">✚</span>
            <span class="brand-name"><span class="brand-evac">Evac</span><span class="brand-tech">Tech</span></span>
        </div>

        <div class="sidebar-user">
            <span class="sidebar-user-role">{{ auth()->user()->barangay?->name ? 'Barangay ' . auth()->user()->barangay->name . ' Staff' : 'Staff' }}</span>
            <span class="sidebar-user-name">{{ auth()->user()->name }}</span>
        </div>

        <nav class="sidebar-nav" aria-label="Main navigation">
            <a href="{{ route('barangay.dashboard') }}" class="nav-item {{ request()->routeIs('barangay.dashboard') ? 'active' : '' }}">
                <span class="nav-icon" aria-hidden="true">▦</span><span class="nav-label">Dashboard</span>
            </a>
            <a href="{{ route('barangay.shelter.index') }}" class="nav-item {{ request()->routeIs('barangay.shelter.*') ? 'active' : '' }}">
                <span class="nav-icon" aria-hidden="true">⌂</span><span class="nav-label">Evacuation Shelter</span>
            </a>
            <a href="{{ route('barangay.evacuees.index') }}" class="nav-item {{ request()->routeIs('barangay.evacuees.*') ? 'active' : '' }}">
                <span class="nav-icon" aria-hidden="true">👥</span><span class="nav-label">Evacuee Profiling</span>
            </a>
            <a href="{{ route('barangay.relief.index') }}" class="nav-item {{ request()->routeIs('barangay.relief.*') ? 'active' : '' }}">
                <span class="nav-icon" aria-hidden="true">📦</span><span class="nav-label">Relief Distribution</span>
            </a>
            <a href="{{ route('barangay.reports.index') }}" class="nav-item {{ request()->routeIs('barangay.reports.*') ? 'active' : '' }}">
                <span class="nav-icon" aria-hidden="true">🗎</span><span class="nav-label">Report Generation</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-controls">
                <button type="button" class="icon-btn" id="themeToggle" aria-label="Toggle dark mode">◐</button>
                <button type="button" class="icon-btn" id="collapseToggle" aria-label="Collapse sidebar">⟨</button>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="signout-btn"><span aria-hidden="true">⎋</span><span class="nav-label"> Sign out</span></button>
            </form>
        </div>
    </aside>

    {{-- ============ Main ============ --}}
    <main class="staff-main">
        <header class="page-head">
            <div>
                <h1 class="page-title">@yield('page-title')</h1>
                <p class="page-subtitle">@yield('page-subtitle')</p>
            </div>
            <div class="page-head-right">
                @yield('page-actions')
            </div>
        </header>

        @if (session('success'))
            <div class="alert alert-success" role="status">✓ {{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="alert alert-danger" role="alert">
                {{ $errors->first() }}
            </div>
        @endif

        @yield('content')
    </main>
</div>

@stack('modals')
@stack('scripts')
</body>
</html>
