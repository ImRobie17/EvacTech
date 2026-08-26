
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // Split entry points (roadmap item 3). charts.js and map.js are
            // SEPARATE entries rather than imports inside app.js so Chart.js is
            // not shipped to citizens and Leaflet is not shipped to staff
            // screens that have no map. A page opts in by setting $viteEntries
            // at the top of the view; the layout passes it to a single @vite()
            // call. Named entries also appear in the Vite manifest, which is
            // what a service worker will enumerate for offline precaching in
            // roadmap item 4 -- a lazily fetched dynamic chunk would not.
            //
            // superadmin.js (Chat C) is the same idea applied to behaviour
            // rather than a library. It used to be ~60 lines of inline <script>
            // at the bottom of superadmin/users/index.blade.php, which meant it
            // could not be cached, could not be precached by a service worker,
            // and could not be linted or syntax-checked. It is now an entry that
            // ONLY the Super Admin layout opts into, so City Admin's bundle
            // stops loading on Super Admin pages entirely.
            //
            // shelter-picker.js (Phase 3 item 10) is the City Admin location
            // picker. It is a SECOND Leaflet entry, and deliberately not folded
            // into map.js: that file is the citizen read-only map, and the two
            // share only a CSS-variable reader and a tile layer. Two entries
            // importing Leaflet do not ship it twice -- Rollup hoists the shared
            // dependency into a common chunk -- so the cost is one extra chunk
            // in the manifest, not a duplicated library.
            //
            // public.js (Phase 4 item B.4) is the citizen bundle. layouts/public
            // used to load app.js, which imports every staff module, so opening
            // the evacuation map downloaded the whole staff interface. The
            // public pages needed one function out of it -- the theme toggle --
            // which now lives in its own module that both entries import.
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/public.js',
                'resources/js/charts.js',
                'resources/js/map.js',
                'resources/js/shelter-picker.js',
                'resources/js/superadmin.js',
            ],
            refresh: true,
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
