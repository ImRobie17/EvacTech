<!DOCTYPE html>
{{--
    Super Admin layout.

    Structurally parallel to layouts/staff and layouts/cityadmin, kept separate
    on purpose. A super admin is most likely at a desktop, but the mobile drawer
    is included anyway so all three staff layouts behave identically -- one
    navigation mechanism to reason about, not three.
--}}
@php
    $sidebarCollapsed = request()->cookie('sidebar') === 'collapsed';

    // CHAT C: Super Admin behaviour moved out of an inline <script> at the
    // bottom of superadmin/users/index.blade.php and into its own bundle.
    //
    // It is loaded from the LAYOUT rather than opted into per view, because the
    // point of the move was to stop City Admin's bundle and Super Admin's
    // behaviour meeting on the same page. Loading it here means every Super
    // Admin screen has its own module available and none of them depend on a
    // view remembering to ask for it.
    //
    // Merged with, not overwriting, anything a view sets: a Super Admin screen
    // that later wants charts.js appends to this list rather than replacing it.
    $viteEntries = array_merge(['resources/js/superadmin.js'], (array) ($viteEntries ?? []));
@endphp
<html lang="en" data-theme="{{ request()->cookie('theme', 'light') }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Super Admin') &mdash; EvacTech</title>
    {{-- ONE @vite() call, deliberately -- a second call in a head stack
         re-injects the HMR client under `npm run dev`.

         NOTE: do not write the PHP-block directive by name in a Blade comment.
         storeUncompiledBlocks() runs BEFORE compileComments(), so naming it here
         opens a real block that swallows markup down to the next closer. --}}
    @vite(array_merge(['resources/css/app.css', 'resources/js/app.js'], (array) ($viteEntries ?? [])))
</head>
<body class="staff-body">
<div class="staff-shell {{ $sidebarCollapsed ? 'sidebar-collapsed' : '' }}">

    <header class="mobile-bar">
        <button type="button" class="mobile-menu-btn" id="mobileNavToggle"
                aria-controls="sidebar" aria-expanded="false">
            <span aria-hidden="true">&#9776;</span>
            <span>Menu</span>
        </button>
        <span class="mobile-bar-brand">
            <span class="brand-mark" aria-hidden="true">&#10010;</span>
            <span class="brand-name"><span class="brand-evac">Evac</span><span class="brand-tech">Tech</span></span>
        </span>
    </header>

    <div class="sidebar-backdrop" id="sidebarBackdrop" hidden></div>

    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <span class="brand-mark" aria-hidden="true">&#10010;</span>
            <span class="brand-name"><span class="brand-evac">Evac</span><span class="brand-tech">Tech</span></span>
        </div>

        <div class="sidebar-user">
            <span class="sidebar-user-role">Super Admin</span>
            <span class="sidebar-user-name">{{ auth()->user()->name }}</span>
        </div>

        <nav class="sidebar-nav" aria-label="Main navigation">
            <a href="{{ route('super.dashboard') }}" class="nav-item {{ request()->routeIs('super.dashboard') ? 'active' : '' }}">
                <span class="nav-icon" aria-hidden="true">&#9638;</span><span class="nav-label">Dashboard</span>
            </a>
            <a href="{{ route('super.users.index') }}" class="nav-item {{ request()->routeIs('super.users.*') ? 'active' : '' }}">
                <span class="nav-icon" aria-hidden="true">&#9881;</span><span class="nav-label">User Management</span>
            </a>
            <a href="{{ route('super.audit.index') }}" class="nav-item {{ request()->routeIs('super.audit.*') ? 'active' : '' }}">
                <span class="nav-icon" aria-hidden="true">&#9782;</span><span class="nav-label">Audit Logs</span>
            </a>
            <a href="{{ route('super.settings.index') }}" class="nav-item {{ request()->routeIs('super.settings.*') ? 'active' : '' }}">
                <span class="nav-icon" aria-hidden="true">&#9211;</span><span class="nav-label">System Settings</span>
            </a>
            <a href="{{ route('super.reports.index') }}" class="nav-item {{ request()->routeIs('super.reports.*') ? 'active' : '' }}">
                <span class="nav-icon" aria-hidden="true">&#128462;</span><span class="nav-label">Report Generation</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-controls">
                <button type="button" class="icon-btn" id="themeToggle" aria-label="Toggle dark mode">&#9680;</button>
                <button type="button" class="icon-btn" id="collapseToggle" aria-label="Collapse sidebar">&#10216;</button>
                <button type="button" class="icon-btn" id="mobileNavClose" aria-label="Close menu">&times;</button>
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
