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
<html lang="en" data-theme="{{ request()->cookie('theme', 'teal-light') }}">
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
            {{-- PHASE 11 - Barangay Management --}}
            <a href="{{ route('super.barangays.index') }}" class="nav-item {{ request()->routeIs('super.barangays.*') ? 'active' : '' }}">
                <span class="nav-icon" aria-hidden="true">&#9878;</span><span class="nav-label">Barangay Management</span>
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
                <div class="relative">
                    <button type="button" class="icon-btn" id="themeSelectorButton" aria-label="Select theme" aria-expanded="false">
                        <span id="themeIndicator" class="inline-block w-4 h-4 rounded-full" style="background-color: var(--color-primary)"></span>
                    </button>
                    <div id="themeDropdown" class="hidden absolute bottom-full left-0 mb-2 w-64 bg-bg border border-border rounded-lg shadow-lg p-2 z-50">
                        <p class="text-xs text-ink-muted mb-2 px-2">Color Theme</p>
                        <div class="grid grid-cols-2 gap-1">
                            <button type="button" data-theme-select="teal-light" class="flex items-center gap-2 p-2 rounded hover:bg-surface transition-colors {{ request()->cookie('theme', 'teal-light') === 'teal-light' ? 'ring-2 ring-offset-2 ring-primary-700' : '' }}">
                                <span class="w-4 h-4 rounded-full" style="background-color: #0e7490"></span>
                                <span class="text-sm">Teal Light</span>
                            </button>
                            <button type="button" data-theme-select="teal-dark" class="flex items-center gap-2 p-2 rounded hover:bg-surface transition-colors {{ request()->cookie('theme', 'teal-light') === 'teal-dark' ? 'ring-2 ring-offset-2 ring-primary-700' : '' }}">
                                <span class="w-4 h-4 rounded-full" style="background-color: #38bdf8"></span>
                                <span class="text-sm">Teal Dark</span>
                            </button>
                            <button type="button" data-theme-select="pink-light" class="flex items-center gap-2 p-2 rounded hover:bg-surface transition-colors {{ request()->cookie('theme', 'teal-light') === 'pink-light' ? 'ring-2 ring-offset-2 ring-primary-700' : '' }}">
                                <span class="w-4 h-4 rounded-full" style="background-color: #be185d"></span>
                                <span class="text-sm">Pink Light</span>
                            </button>
                            <button type="button" data-theme-select="pink-dark" class="flex items-center gap-2 p-2 rounded hover:bg-surface transition-colors {{ request()->cookie('theme', 'teal-light') === 'pink-dark' ? 'ring-2 ring-offset-2 ring-primary-700' : '' }}">
                                <span class="w-4 h-4 rounded-full" style="background-color: #fb7185"></span>
                                <span class="text-sm">Pink Dark</span>
                            </button>
                            <button type="button" data-theme-select="green-light" class="flex items-center gap-2 p-2 rounded hover:bg-surface transition-colors {{ request()->cookie('theme', 'teal-light') === 'green-light' ? 'ring-2 ring-offset-2 ring-primary-700' : '' }}">
                                <span class="w-4 h-4 rounded-full" style="background-color: #15803d"></span>
                                <span class="text-sm">Green Light</span>
                            </button>
                            <button type="button" data-theme-select="green-dark" class="flex items-center gap-2 p-2 rounded hover:bg-surface transition-colors {{ request()->cookie('theme', 'teal-light') === 'green-dark' ? 'ring-2 ring-offset-2 ring-primary-700' : '' }}">
                                <span class="w-4 h-4 rounded-full" style="background-color: #86efac"></span>
                                <span class="text-sm">Green Dark</span>
                            </button>
                            <button type="button" data-theme-select="blue-light" class="flex items-center gap-2 p-2 rounded hover:bg-surface transition-colors {{ request()->cookie('theme', 'teal-light') === 'blue-light' ? 'ring-2 ring-offset-2 ring-primary-700' : '' }}">
                                <span class="w-4 h-4 rounded-full" style="background-color: #1d4ed8"></span>
                                <span class="text-sm">Blue Light</span>
                            </button>
                            <button type="button" data-theme-select="blue-dark" class="flex items-center gap-2 p-2 rounded hover:bg-surface transition-colors {{ request()->cookie('theme', 'teal-light') === 'blue-dark' ? 'ring-2 ring-offset-2 ring-primary-700' : '' }}">
                                <span class="w-4 h-4 rounded-full" style="background-color: #93c5fd"></span>
                                <span class="text-sm">Blue Dark</span>
                            </button>
                            <button type="button" data-theme-select="purple-light" class="flex items-center gap-2 p-2 rounded hover:bg-surface transition-colors {{ request()->cookie('theme', 'teal-light') === 'purple-light' ? 'ring-2 ring-offset-2 ring-primary-700' : '' }}">
                                <span class="w-4 h-4 rounded-full" style="background-color: #7c3aed"></span>
                                <span class="text-sm">Purple Light</span>
                            </button>
                            <button type="button" data-theme-select="purple-dark" class="flex items-center gap-2 p-2 rounded hover:bg-surface transition-colors {{ request()->cookie('theme', 'teal-light') === 'purple-dark' ? 'ring-2 ring-offset-2 ring-primary-700' : '' }}">
                                <span class="w-4 h-4 rounded-full" style="background-color: #d8b4fe"></span>
                                <span class="text-sm">Purple Dark</span>
                            </button>
                            <button type="button" data-theme-select="orange-light" class="flex items-center gap-2 p-2 rounded hover:bg-surface transition-colors {{ request()->cookie('theme', 'teal-light') === 'orange-light' ? 'ring-2 ring-offset-2 ring-primary-700' : '' }}">
                                <span class="w-4 h-4 rounded-full" style="background-color: #c2410c"></span>
                                <span class="text-sm">Orange Light</span>
                            </button>
                            <button type="button" data-theme-select="orange-dark" class="flex items-center gap-2 p-2 rounded hover:bg-surface transition-colors {{ request()->cookie('theme', 'teal-light') === 'orange-dark' ? 'ring-2 ring-offset-2 ring-primary-700' : '' }}">
                                <span class="w-4 h-4 rounded-full" style="background-color: #fdba74"></span>
                                <span class="text-sm">Orange Dark</span>
                            </button>
                        </div>
                        <div class="border-t border-border mt-2 pt-2">
                            <p class="text-xs text-ink-muted mb-2 px-2">Accessibility</p>
                            <button type="button" data-theme-select="high-contrast-light" class="flex items-center gap-2 p-2 rounded hover:bg-surface transition-colors w-full {{ request()->cookie('theme', 'teal-light') === 'high-contrast-light' ? 'ring-2 ring-offset-2 ring-primary-700' : '' }}">
                                <span class="w-4 h-4 rounded-full bg-black border border-white"></span>
                                <span class="text-sm">High Contrast Light</span>
                            </button>
                            <button type="button" data-theme-select="high-contrast-dark" class="flex items-center gap-2 p-2 rounded hover:bg-surface transition-colors w-full {{ request()->cookie('theme', 'teal-light') === 'high-contrast-dark' ? 'ring-2 ring-offset-2 ring-primary-700' : '' }}">
                                <span class="w-4 h-4 rounded-full bg-white border border-black"></span>
                                <span class="text-sm">High Contrast Dark</span>
                            </button>
                        </div>
                    </div>
                </div>
                <button type="button" class="icon-btn" id="collapseToggle" aria-label="Collapse sidebar">&#10216;</button>
                <button type="button" class="icon-btn" id="mobileNavClose" aria-label="Close menu">&times;</button>
            </div>
            <form method="POST" action="{{ route('logout') }}" id="logoutForm">
                @csrf
                <button type="button" class="signout-btn" id="signoutBtn"><span aria-hidden="true">&#9099;</span><span class="nav-label"> Sign out</span></button>
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

{{-- Sign-out confirmation modal --}}
<div id="signoutModal" class="modal-backdrop" hidden>
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="signoutModalTitle">
        <h2 id="signoutModalTitle" class="text-lg font-semibold mb-2">Sign Out</h2>
        <p class="mb-6 text-ink-soft">Are you sure you want to sign out? You will need to log in again to access the system.</p>
        <div class="flex gap-3 justify-end">
            <button type="button" class="btn-secondary" id="cancelSignout">Cancel</button>
            <button type="button" class="btn-primary" id="confirmSignout">Sign Out</button>
        </div>
    </div>
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const signoutBtn = document.getElementById('signoutBtn');
        const signoutModal = document.getElementById('signoutModal');
        const cancelSignout = document.getElementById('cancelSignout');
        const confirmSignout = document.getElementById('confirmSignout');
        const logoutForm = document.getElementById('logoutForm');

        if (signoutBtn && signoutModal) {
            signoutBtn.addEventListener('click', function() {
                signoutModal.hidden = false;
                cancelSignout.focus();
            });

            cancelSignout.addEventListener('click', function() {
                signoutModal.hidden = true;
                signoutBtn.focus();
            });

            confirmSignout.addEventListener('click', function() {
                logoutForm.submit();
            });

            // Close on backdrop click
            signoutModal.addEventListener('click', function(e) {
                if (e.target === signoutModal) {
                    signoutModal.hidden = true;
                    signoutBtn.focus();
                }
            });

            // Close on Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && !signoutModal.hidden) {
                    signoutModal.hidden = true;
                    signoutBtn.focus();
                }
            });
        }
    });
</script>
@endpush

@stack('scripts')
</body>
</html>
