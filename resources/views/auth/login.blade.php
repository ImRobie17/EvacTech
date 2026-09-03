<!DOCTYPE html>
{{--
    Staff sign-in. Standalone document -- it does not extend a layout.
    The <title> previously contained a raw em dash; it is now the &mdash;
    entity, per the pure-ASCII rule.

    SHOW-PASSWORD DROP. This page still loads app.css and nothing else. The
    toggle below is served by a small inline script at the bottom of this file
    rather than by a Vite entry, for two reasons.

    Adding app.js here would drag staff.js, cityadmin.js, cityadmin-shelter.js
    and transfers.js onto a page that has none of their markup. Their init
    functions are guarded, so nothing would break -- they would simply print
    their "the element I need is missing" errors into the console on the first
    screen anybody sees, including a panelist.

    A new Vite entry would have been the tidier option by convention, but it
    costs a vite.config.js change and an npm run build, and this drop otherwise
    needs neither.
--}}
<html lang="en" data-theme="{{ request()->cookie('theme', 'light') }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Login &mdash; EvacTech</title>
    @vite(['resources/css/app.css'])
</head>
<body class="login-body">
    <main class="login-card" aria-labelledby="login-title">
        <div class="login-brand">
            <span class="brand-mark" aria-hidden="true">&#10010;</span>
            <span class="brand-name"><span class="brand-evac">Evac</span><span class="brand-tech">Tech</span></span>
        </div>
        <h1 id="login-title" class="login-title">Staff Sign In</h1>
        <p class="login-subtitle">Evacuation management portal for the City of Cabuyao.</p>

        @if (cache()->get('evactech_maintenance', false))
            <div class="alert alert-warning" role="status">
                <strong>System under maintenance.</strong> Only system administrators can sign in right now. Please try again later.
            </div>
        @endif

        {{-- PHASE 7 ITEM 5. The reset-request acknowledgement lands here after a
             redirect back to this page. Identical wording whether or not the
             email matched an account, so the form cannot be used to find out
             which addresses exist. --}}
        @if (session('success'))
            <div class="alert alert-success" role="status">{{ session('success') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('login.attempt') }}" class="login-form">
            @csrf
            <div class="field">
                <label for="email">Email address</label>
                <input type="email" id="email" name="email" value="{{ old('email') }}" required autocomplete="username">
            </div>
            <div class="field">
                {{-- The label and the toggle share one row so the control sits
                     where the eye already is. No new stylesheet classes: a flex
                     row of Tailwind utilities over the existing .btn-link. --}}
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <label for="password">Password</label>
                    {{-- HIDDEN UNTIL THE SCRIPT CLAIMS IT. If the script fails,
                         is blocked, or never runs, this button stays invisible
                         rather than sitting there doing nothing when pressed.
                         [hidden] is reliable here because design-system.css
                         restates display:none !important for it.

                         type="button" is load-bearing: the default type inside a
                         form is submit, and a bare <button> here would post the
                         login form on the first click. --}}
                    <button type="button"
                            id="pw-toggle"
                            class="btn-link min-h-[44px]"
                            aria-pressed="false"
                            aria-controls="password"
                            hidden>Show password</button>
                </div>
                {{-- NOT TOUCHED by this drop: type, id, name, required and
                     autocomplete are all exactly as they were. The script only
                     ever swaps `type` between password and text at runtime, so
                     with the script absent this input behaves identically to the
                     version that shipped in Phase 7. --}}
                <input type="password" id="password" name="password" required autocomplete="current-password">
            </div>
            <label class="checkbox-row">
                <input type="checkbox" name="remember" value="1"> Keep me signed in
            </label>
            <button type="submit" class="btn-primary btn-block">Sign in</button>
        </form>

        {{-- PHASE 7 ITEM 5. A link, not a modal: this page is a standalone
             document that loads app.css and no JavaScript at all, so there is
             nothing here to open one with. --}}
        <p class="login-subtitle">
            Forgotten your password, or locked out?
            <a href="{{ route('password.request') }}" class="btn-link">Request a reset</a>
        </p>
    </main>

    {{-- SHOW-PASSWORD TOGGLE.

         Deliberately the only script on this page, and deliberately inline.
         It touches nothing but the password input's `type` attribute and the
         button's own label, so every failure mode leaves an ordinary working
         login form behind.

         GOTCHA 5 applies inside this block: Blade compiles comments too, so no
         echo syntax and no directives appear in the JavaScript comments below.

         SECURITY POSTURE. A reveal control on a shared shelter workstation is a
         shoulder-surfing surface. It is off on every page load, it is never
         persisted to a cookie or to storage, and nothing carries the revealed
         state across a navigation. It exists because a Camp Manager thumbing a
         password into a phone in the field gets it wrong three times and locks
         the account -- MAX_LOGIN_ATTEMPTS is 3 and LOCK_MINUTES is 15, and an
         unlock needs an administrator. Trading a little shoulder-surfing risk
         against a lockout that takes a shelter's only account offline mid
         operation is the right way round. --}}
    <script>
        (function () {
            var input = document.getElementById('password');
            var toggle = document.getElementById('pw-toggle');

            if (!input || !toggle) {
                console.error('[EvacTech/login] show-password: the password input or the toggle button is missing, so the toggle stays hidden. Sign-in itself is unaffected.');
                return;
            }

            // The script reveals its own control. A button that is visible
            // before this line has run is a button that might do nothing.
            toggle.hidden = false;

            toggle.addEventListener('click', function () {
                var reveal = input.type === 'password';
                var start = input.selectionStart;
                var end = input.selectionEnd;

                input.type = reveal ? 'text' : 'password';
                toggle.textContent = reveal ? 'Hide password' : 'Show password';
                toggle.setAttribute('aria-pressed', reveal ? 'true' : 'false');

                // Changing type moves focus off the field in some browsers and
                // drops the caret to position zero in others. Put both back so
                // the operator can keep typing where they left off.
                input.focus();

                try {
                    input.setSelectionRange(start, end);
                } catch (err) {
                    // A browser that refuses setSelectionRange straight after a
                    // type change is not a reason to abandon the toggle. The
                    // caret lands at the end of the value instead.
                }
            });
        })();
    </script>
</body>
</html>
