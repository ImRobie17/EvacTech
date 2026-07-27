
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
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/charts.js',
                'resources/js/map.js',
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
