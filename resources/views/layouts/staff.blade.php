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
    // Fed by the layouts.staff view composer in AppServiceProvider, so every
    // screen names its shelter without touching its controller. It carried the
    // switcher until Phase 6 item 7; it now feeds the shelter name in .page-head.
    $navCenters = $navCenters ?? collect();
    $navActiveCenter = $navCenters->firstWhere('id', $navActiveCenterId ?? null);

    // PHASE 2 ITEM 8. Fed by the same composer as the alert bar. Defaults are
    // set here so the layout still renders if the composer ever returns early.
    $navTransferCount = (int) (($transferAlerts['needs_action'] ?? 0) + ($transferAlerts['overdue'] ?? 0));
    $navTransferGlow = $navTransferCount > 0;
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
                {{ $navActiveCenter?->barangay?->name ? 'Brgy. ' . $navActiveCenter->barangay->name . ' Camp Manager' : 'Camp Manager' }}
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
            {{-- PHASE 2 ITEM 8. The glow is a Tailwind ring plus a count, never
                 colour alone: the number of items needing this user's attention
                 is printed as text beside the label. --}}
            <a href="{{ route('barangay.transfers.index') }}" class="nav-item {{ request()->routeIs('barangay.transfers.*') ? 'active' : '' }} {{ $navTransferGlow ? 'ring-2 ring-red-500' : '' }}">
                <span class="nav-icon" aria-hidden="true">&#8646;</span><span class="nav-label">Shelter Transfers @if($navTransferGlow)<span class="badge badge-danger">{{ $navTransferCount }}</span>@endif</span>
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
        {{-- PHASE 6 ITEM 7. The sticky "Working in <shelter>" strip that used
             to sit here is gone, and the shelter switcher inside it with it.

             The strip cost a permanent band of vertical space on a 380px phone,
             which is the device barangay staff actually work from. The switcher
             had no job left: a staff member is physically inside ONE shelter for
             one shift, and moving them to another is a REASSIGNMENT that City
             Admin performs, not a choice made from the header. When City Admin
             reassigns an account, ResolvesCenter::resolveCenter() finds the
             stored session id is no longer on the roster and falls back to the
             new assignment on the very next page load, so nothing needs
             clicking.

             The shelter name moved into .page-head below, non-sticky, built
             from Tailwind utilities. $navCenters and $navActiveCenterId still
             come from the layouts.staff view composer -- they name the shelter
             and are still what the empty state below tests. --}}

        {{-- PHASE 2 ITEM 8 -- pinned above the page heading on every barangay
             screen. It sat below the shelter switcher until Phase 6 item 7
             removed that strip; it is now the first thing in the main region. --}}
        @include('partials.transfer-alert-bar')

        <header class="page-head">
            <div class="min-w-0">
                {{-- PHASE 6 ITEM 7 -- replaces the sticky strip. Non-sticky, so
                     it scrolls away with the rest of the header. Rendered above
                     the page title because it is CONTEXT for the title, not a
                     heading of its own: it answers "where am I" before the page
                     answers "what is this". Utilities only -- no class was added
                     to staff.css for it. --}}
                @if ($navActiveCenter)
                    <p class="mb-1 flex flex-wrap items-baseline gap-x-2 text-sm text-ink-soft">
                        <span class="font-semibold text-ink">{{ $navActiveCenter->name }}</span>
                        @if ($navActiveCenter->barangay)
                            <span>Brgy. {{ $navActiveCenter->barangay->name }}</span>
                        @endif
                        @php
                            // Occupancy is DERIVED; this only reads it. The band
                            // is computed by the model, never here.
                            $navBand = $navActiveCenter->capacityBand();
                        @endphp
                        <span class="font-mono font-semibold cap-{{ $navBand }}">{{ number_format($navActiveCenter->current_occupancy) }} / {{ number_format($navActiveCenter->capacity) }}</span>
                        {{-- Status is never colour alone: each state prints its
                             own words beside the figure. --}}
                        @if ($navActiveCenter->isOvercapacity())
                            <span class="badge badge-over">Overcapacity +{{ number_format($navActiveCenter->overBy()) }}</span>
                        @elseif (! $navActiveCenter->isActive())
                            <span class="badge badge-warning">{{ $navActiveCenter->statusLabel() }}</span>
                        @endif
                    </p>
                @endif
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
