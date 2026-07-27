<!DOCTYPE html>
{{--
    Barangay Personnel layout.

    Deliberately NOT shared with the City Admin or Super Admin layouts even
    though the three are similar. Cross-role view reuse is what produced the
    routing and check-out bugs that Phase 1 item 1b removed; duplicated markup
    is the cheaper problem.

    Roadmap item 13 is implemented here: the sidebar is sticky and full height
    with Sign out outside the scrolling region, its collapsed state is read from
    a cookie server side (so it never flashes open on navigation), and below
    1024px it becomes an off-canvas drawer. The previous layout had no mobile
    navigation whatsoever -- the stylesheet simply hid the sidebar under 720px.
--}}
@php
    // Read server side rather than in JS: applying the class during render is
    // what prevents the expanded-then-collapsed flash on every page load.
    $sidebarCollapsed = request()->cookie('sidebar') === 'collapsed';
@endphp
<html lang="en" data-theme="{{ request()->cookie('theme', 'light') }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'EvacTech') &mdash; EvacTech</title>
    {{-- Fonts are bundled by Vite from node_modules (roadmap item 3). The
         Google Fonts <link> tags are gone from every layout: the app must work
         with no internet connection.

         $viteEntries lets a view opt into an extra bundle (charts.js, map.js)
         without shipping it to every page. A view sets it in a PHP block at its
         top; because Blade renders the child before the layout and passes the
         child's variables up, the value is in scope here.

         ONE @vite() call, deliberately. A second call in a head stack would
         re-inject the HMR client during `npm run dev`.

         NOTE: do not write the PHP-block directive by name in a Blade comment.
         BladeCompiler::compileString calls storeUncompiledBlocks() BEFORE
         compileComments(), so that directive is extracted from comments too --
         a mention of it here opened a block that swallowed everything down to
         the real closer below, including the $navCenters assignment, and the
         sidebar died with "Undefined variable $navActiveCenter". --}}
    @vite(array_merge(['resources/css/app.css', 'resources/js/app.js'], (array) ($viteEntries ?? [])))
</head>
<body class="staff-body">
@php
    // Fed by the layouts.staff view composer in AppServiceProvider, so Dashboard
    // and Reports get the switcher too without touching their controllers.
    $navCenters = $navCenters ?? collect();
    $navActiveCenter = $navCenters->firstWhere('id', $navActiveCenterId ?? null);
@endphp
<div class="staff-shell {{ $sidebarCollapsed ? 'sidebar-collapsed' : '' }}">

    {{-- ============ Mobile top bar (hidden at 1024px and up) ============ --}}
    <header class="mobile-bar">
        {{-- Labelled "Menu", not a bare hamburger. A naked icon is the most
             reliable way to lose a non-technical user. --}}
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

    {{-- ============ Sidebar / mobile drawer ============ --}}
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <span class="brand-mark" aria-hidden="true">&#10010;</span>
            <span class="brand-name"><span class="brand-evac">Evac</span><span class="brand-tech">Tech</span></span>
        </div>

        <div class="sidebar-user">
            {{-- Staff are no longer identified by barangay: they are identified by
                 the shelter they are currently operating. --}}
            <span class="sidebar-user-role">
                {{ $navActiveCenter?->barangay?->name ? 'Brgy. ' . $navActiveCenter->barangay->name . ' Staff' : 'Shelter Staff' }}
            </span>
            <span class="sidebar-user-name">{{ auth()->user()->name }}</span>
        </div>

        <nav class="sidebar-nav" aria-label="Main navigation">
            <a href="{{ route('barangay.dashboard') }}" class="nav-item {{ request()->routeIs('barangay.dashboard') ? 'active' : '' }}">
                <span class="nav-icon" aria-hidden="true">&#9638;</span><span class="nav-label">Dashboard</span>
            </a>
            <a href="{{ route('barangay.shelter.index') }}" class="nav-item {{ request()->routeIs('barangay.shelter.index') ? 'active' : '' }}">
                <span class="nav-icon" aria-hidden="true">&#8962;</span><span class="nav-label">Evacuation Shelter</span>
            </a>
            <a href="{{ route('barangay.evacuees.index') }}" class="nav-item {{ request()->routeIs('barangay.evacuees.*') ? 'active' : '' }}">
                <span class="nav-icon" aria-hidden="true">&#128101;</span><span class="nav-label">Evacuee Profiling</span>
            </a>
            <a href="{{ route('barangay.relief.index') }}" class="nav-item {{ request()->routeIs('barangay.relief.*') ? 'active' : '' }}">
                <span class="nav-icon" aria-hidden="true">&#128230;</span><span class="nav-label">Relief Distribution</span>
            </a>
            <a href="{{ route('barangay.reports.index') }}" class="nav-item {{ request()->routeIs('barangay.reports.*') ? 'active' : '' }}">
                <span class="nav-icon" aria-hidden="true">&#128462;</span><span class="nav-label">Report Generation</span>
            </a>
        </nav>

        {{-- Outside .sidebar-nav on purpose. The nav scrolls; this does not, so
             Sign out is reachable at any window height without scrolling. --}}
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

    {{-- ============ Main ============ --}}
    <main class="staff-main">
        {{-- ---- Active shelter switcher ----
             One barangay can now hold many shelters and staff can be rostered to
             several, so every screen states which shelter it is acting on. The
             choice persists in the session until changed. Sticky, so it stays
             reachable at 380px without scrolling. --}}
        @if ($navCenters->count() > 0)
            <div class="shelter-context {{ $navCenters->count() > 1 ? '' : 'shelter-context-single' }}">
                <span class="shelter-context-label">Working in</span>

                @if ($navCenters->count() > 1)
                    <form method="POST" action="{{ route('barangay.shelter.switch') }}" id="shelterSwitchForm" class="shelter-switch">
                        @csrf
                        <label class="visually-hidden" for="activeShelter">Active evacuation shelter</label>
                        <select name="center_id" id="activeShelter" class="shelter-switch-select">
                            @foreach ($navCenters as $c)
                                <option value="{{ $c->id }}" @selected($c->id === $navActiveCenterId)>
                                    {{ $c->name }}@if($c->barangay) &middot; Brgy. {{ $c->barangay->name }}@endif
                                </option>
                            @endforeach
                        </select>
                        <button type="submit" class="btn-secondary shelter-switch-btn">Switch</button>
                    </form>
                @else
                    <strong class="shelter-context-name">
                        {{ $navActiveCenter?->name }}@if($navActiveCenter?->barangay) &middot; Brgy. {{ $navActiveCenter->barangay->name }}@endif
                    </strong>
                @endif

                @if ($navActiveCenter)
                    @php $band = $navActiveCenter->capacityBand(); @endphp
                    <span class="shelter-context-meta {{ 'cap-' . $band }}">
                        {{ number_format($navActiveCenter->current_occupancy) }} /
                        {{ number_format($navActiveCenter->capacity) }}
                        @if ($navActiveCenter->isOvercapacity())
                            <span class="badge badge-over">Overcapacity +{{ number_format($navActiveCenter->overBy()) }}</span>
                        @elseif (! $navActiveCenter->isActive())
                            <span class="badge badge-warning">{{ $navActiveCenter->statusLabel() }}</span>
                        @endif
                    </span>
                @endif
            </div>
        @endif

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
            <div class="alert alert-success" role="status">&check; {{ session('success') }}</div>
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
