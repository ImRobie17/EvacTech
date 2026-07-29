<!DOCTYPE html>
{{--
    City Admin (CDRRMO) layout.

    Structurally parallel to layouts/staff and layouts/superadmin but kept
    separate on purpose -- see the note in layouts/staff about cross-role view
    reuse. Only the navigation list and the role label differ.

    Two bugs fixed in passing:
      * the <title> contained a raw em dash, the exact class of non-ASCII glyph
        that has been double-encoded into mojibake in this codebase before. It
        is now the &mdash; entity.
      * every .nav-icon span was empty. The icons had been stripped at some
        point, leaving six blank 22px gutters. They are restored as entities.
--}}
@php
    $sidebarCollapsed = request()->cookie('sidebar') === 'collapsed';
@endphp
<html lang="en" data-theme="{{ request()->cookie('theme', 'light') }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'City Admin') &mdash; EvacTech</title>
    {{-- CHAT C: $viteEntries support, matching layouts/staff. A view opts into
         an extra bundle (charts.js, map.js) by setting the variable in a PHP
         block at its top; Blade renders the child before the layout and passes
         the child's variables up, so the value is in scope here.

         This line is what the City Admin dashboard needed before its Chart.js
         cdnjs <script> tag could be deleted -- the last CDN reference in the
         codebase, and the last thing standing between this app and running with
         no internet connection.

         ONE @vite() call, deliberately. A second call in a head stack would
         re-inject the HMR client during `npm run dev`.

         NOTE: do not write the PHP-block directive by name in a Blade comment.
         BladeCompiler::compileString calls storeUncompiledBlocks() BEFORE
         compileComments(), so that directive is extracted from comments too --
         a mention of it opens a real block that swallows everything down to the
         next genuine closer. --}}
    @vite(array_merge(['resources/css/app.css', 'resources/js/app.js'], (array) ($viteEntries ?? [])))
</head>
<body class="staff-body">
@php
    // PHASE 2 ITEM 8. Same composer as the barangay layout; same defaults.
    $navTransferCount = (int) (($transferAlerts['needs_action'] ?? 0) + ($transferAlerts['overdue'] ?? 0));
    $navTransferGlow = $navTransferCount > 0;
@endphp
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
            <span class="sidebar-user-role">City Admin</span>
            <span class="sidebar-user-name">{{ auth()->user()->name }}</span>
        </div>

        <nav class="sidebar-nav" aria-label="Main navigation">
            <a href="{{ route('city.dashboard') }}" class="nav-item {{ request()->routeIs('city.dashboard') ? 'active' : '' }}">
                <span class="nav-icon" aria-hidden="true">&#9638;</span><span class="nav-label">Dashboard</span>
            </a>
            <a href="{{ route('city.shelters.index') }}" class="nav-item {{ request()->routeIs('city.shelters.*') ? 'active' : '' }}">
                <span class="nav-icon" aria-hidden="true">&#8962;</span><span class="nav-label">Evacuation Shelters</span>
            </a>
            <a href="{{ route('city.evacuees.index') }}" class="nav-item {{ request()->routeIs('city.evacuees.*') ? 'active' : '' }}">
                <span class="nav-icon" aria-hidden="true">&#128101;</span><span class="nav-label">Evacuee Profiling</span>
            </a>
            <a href="{{ route('city.relief.index') }}" class="nav-item {{ request()->routeIs('city.relief.*') ? 'active' : '' }}">
                <span class="nav-icon" aria-hidden="true">&#128230;</span><span class="nav-label">Relief Distribution</span>
            </a>
            {{-- PHASE 2 ITEM 8. Count printed as text: the ring is never the
                 only signal that something needs attention. --}}
            <a href="{{ route('city.transfers.index') }}" class="nav-item {{ request()->routeIs('city.transfers.*') ? 'active' : '' }} {{ $navTransferGlow ? 'ring-2 ring-red-500' : '' }}">
                <span class="nav-icon" aria-hidden="true">&#8646;</span><span class="nav-label">Shelter Transfers @if($navTransferGlow)<span class="badge badge-danger">{{ $navTransferCount }}</span>@endif</span>
            </a>
            <a href="{{ route('city.reports.index') }}" class="nav-item {{ request()->routeIs('city.reports.*') ? 'active' : '' }}">
                <span class="nav-icon" aria-hidden="true">&#128462;</span><span class="nav-label">Report Generation</span>
            </a>
            <a href="{{ route('city.users.index') }}" class="nav-item {{ request()->routeIs('city.users.*') ? 'active' : '' }}">
                <span class="nav-icon" aria-hidden="true">&#9881;</span><span class="nav-label">User Management</span>
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
        {{-- PHASE 2 ITEM 8 -- pinned at the top of the content area. --}}
        @include('partials.transfer-alert-bar')

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
