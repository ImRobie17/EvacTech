// ---------------------------------------------------------------------
// Multi-theme system (server-persisted via cookie, no localStorage)
// ---------------------------------------------------------------------
// Supports color themes with light/dark modes: teal-light, pink-dark, etc.
// The cookie is read server side in every layout and written onto <html data-theme>,
// so there is no flash of the wrong theme on navigation. The cookie is excluded
// from encryptCookies in bootstrap/app.php precisely so Blade can read it.
//
// Bound by delegation on document rather than by looking the button up at
// DOMContentLoaded: the button exists in four different layouts, and a lookup
// that misses simply does nothing and says nothing. Delegation cannot miss.

const THEMES = [
    { id: 'teal-light', name: 'Teal Light', color: '#0e7490' },
    { id: 'teal-dark', name: 'Teal Dark', color: '#38bdf8' },
    { id: 'pink-light', name: 'Pink Light', color: '#be185d' },
    { id: 'pink-dark', name: 'Pink Dark', color: '#fb7185' },
    { id: 'green-light', name: 'Green Light', color: '#15803d' },
    { id: 'green-dark', name: 'Green Dark', color: '#86efac' },
    { id: 'blue-light', name: 'Blue Light', color: '#1d4ed8' },
    { id: 'blue-dark', name: 'Blue Dark', color: '#93c5fd' },
    { id: 'purple-light', name: 'Purple Light', color: '#7c3aed' },
    { id: 'purple-dark', name: 'Purple Dark', color: '#d8b4fe' },
    { id: 'orange-light', name: 'Orange Light', color: '#c2410c' },
    { id: 'orange-dark', name: 'Orange Dark', color: '#fdba74' },
    { id: 'high-contrast-light', name: 'High Contrast Light', color: '#000000' },
    { id: 'high-contrast-dark', name: 'High Contrast Dark', color: '#ffffff' },
];

const DEFAULT_THEME = 'teal-light';

export function initTheme() {
    // Handle theme toggle button (legacy - switches between light/dark of current color)
    document.addEventListener('click', (e) => {
        const toggle = e.target.closest('#themeToggle');
        if (!toggle) return;

        const html = document.documentElement;
        const current = html.getAttribute('data-theme') || DEFAULT_THEME;
        const [color, mode] = current.split('-');
        const nextMode = mode === 'dark' ? 'light' : 'dark';
        const next = `${color}-${nextMode}`;
        
        html.setAttribute('data-theme', next);
        document.cookie = `theme=${next};path=/;max-age=31536000`;
    });

    // Handle theme selector dropdown
    document.addEventListener('click', (e) => {
        const selector = e.target.closest('#themeSelectorButton');
        if (!selector) return;

        const dropdown = document.getElementById('themeDropdown');
        if (dropdown) {
            dropdown.classList.toggle('hidden');
        }
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', (e) => {
        const selector = e.target.closest('#themeSelectorButton');
        const dropdown = document.getElementById('themeDropdown');
        
        if (!selector && dropdown && !dropdown.contains(e.target)) {
            dropdown.classList.add('hidden');
        }
    });

    // Handle theme selection
    document.addEventListener('click', (e) => {
        const themeOption = e.target.closest('[data-theme-select]');
        if (!themeOption) return;

        const theme = themeOption.getAttribute('data-theme-select');
        const html = document.documentElement;
        
        html.setAttribute('data-theme', theme);
        document.cookie = `theme=${theme};path=/;max-age=31536000`;
        
        // Update active state in dropdown
        document.querySelectorAll('[data-theme-select]').forEach(el => {
            el.classList.remove('ring-2', 'ring-offset-2', 'ring-primary-700');
            if (el.getAttribute('data-theme-select') === theme) {
                el.classList.add('ring-2', 'ring-offset-2', 'ring-primary-700');
            }
        });

        // Close dropdown
        const dropdown = document.getElementById('themeDropdown');
        if (dropdown) {
            dropdown.classList.add('hidden');
        }
    });
}

export function getThemes() {
    return THEMES;
}

export function getCurrentTheme() {
    return document.documentElement.getAttribute('data-theme') || DEFAULT_THEME;
}
