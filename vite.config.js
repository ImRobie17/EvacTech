
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
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/charts.js',
                'resources/js/map.js',
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
