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
    {{-- $viteEntries lets a view opt into an extra bundle (map.js on the
         evacuation map) without shipping Leaflet to every citizen page. A view
         sets it in a PHP block at its top. One @vite() call only: a second call
         in a head stack would re-inject the HMR client under `npm run dev`.

         Harmless here only because this layout has no PHP block of its own --
         naming that directive inside a Blade comment opens a real one, since
         storeUncompiledBlocks() runs before compileComments(). It broke
         layouts/staff exactly that way. Do not reintroduce it. --}}
    {{-- PHASE 4 item B.4: public.js, NOT app.js. app.js imports staff.js,
         cityadmin.js, cityadmin-shelter.js and transfers.js, so this layout was
         shipping the entire staff interface to citizens in order to get one
         theme toggle. public.js carries the toggle and the form-trim fix and
         nothing else. The stylesheet is still shared -- one cached bundle. --}}
    @vite(array_merge(['resources/css/app.css', 'resources/js/public.js'], (array) ($viteEntries ?? [])))
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

            {{-- PHASE 6 ITEM 9. The "Staff Login" anchor that used to sit here is
                 gone. The citizen pages are the one part of EvacTech with no
                 authentication at all, and advertising the staff entrance from
                 them serves nobody: a citizen cannot use it, and staff reach
                 /login directly. Removing the link does not remove the route --
                 it is one less thing pointed at the login form from a page
                 anyone on the internet can open.

                 The Logout form STAYS. It renders only for an already
                 authenticated session, so no citizen ever sees it, and without
                 it a staff member who wandered onto the public map would have no
                 way to sign out from there. --}}
            @auth
                <form method="POST" action="{{ route('logout') }}" class="inline-form">
                    @csrf
                    <button type="submit" class="staff-login-link">Logout</button>
                </form>
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
