// ---------------------------------------------------------------------
// Theme toggle (server-persisted via cookie, no localStorage)
// ---------------------------------------------------------------------
// PHASE 4 (item B.4). This used to be initTheme() inside staff.js. The public
// layout loaded app.js purely to get it, which meant every citizen visiting the
// evacuation map also downloaded staff.js, cityadmin.js, cityadmin-shelter.js
// and transfers.js -- the whole staff interface, on a phone, during a flood.
//
// It lives here so public.js can import ONE small module and staff.js can keep
// calling it. Behaviour is unchanged: the `theme` cookie is read server side in
// every layout and written onto <html data-theme>, so there is no flash of the
// wrong theme on navigation. The cookie is excluded from encryptCookies in
// bootstrap/app.php precisely so Blade can read it.
//
// Bound by delegation on document rather than by looking the button up at
// DOMContentLoaded: the button exists in four different layouts, and a lookup
// that misses simply does nothing and says nothing. Delegation cannot miss.

export function initTheme() {
    document.addEventListener('click', (e) => {
        const toggle = e.target.closest('#themeToggle');
        if (!toggle) return;

        const html = document.documentElement;
        const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        html.setAttribute('data-theme', next);
        document.cookie = `theme=${next};path=/;max-age=31536000`;
    });
}
