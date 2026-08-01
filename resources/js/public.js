// ---------------------------------------------------------------------
// Public Citizen entry point
// ---------------------------------------------------------------------
// PHASE 4 (item B.4). layouts/public.blade.php used to load app.js, which
// imports staff.js, cityadmin.js, cityadmin-shelter.js and transfers.js. A
// citizen opening the evacuation map was therefore downloading the entire staff
// interface -- every modal handler, the evacuee registration form and the whole
// transfer workflow -- to render three read-only pages. Wasted bytes on a phone
// during a flood, and the structure of the admin UI readable in view-source by
// anyone.
//
// The public pages needed exactly ONE thing from that pile: the theme toggle.
// So they get the theme toggle.
//
// Leaflet is still opted into per page: public/map.blade.php sets
// $viteEntries = ['resources/js/map.js'] and the layout merges it into the
// single @vite() call. Find Family and Hotlines ship no map code at all.
//
// axios is deliberately NOT imported here. bootstrap.js assigns it to
// window.axios for the staff bundle, but nothing on a citizen page uses it --
// Find Family is a plain POST form and the map reads data from a JSON island.

import { initTheme } from './theme';
import { initFormHygiene } from './form-hygiene';

initTheme();
initFormHygiene();
