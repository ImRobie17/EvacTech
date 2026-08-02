import './bootstrap';
// Whitespace-only input must not satisfy `required` (Phase 4 item 15a). Loaded
// for every staff screen; public.js imports the same module for the citizen
// pages, which no longer load this file at all.
import { initFormHygiene } from './form-hygiene';
import './staff.js';
import './cityadmin.js';
// City Admin shelter detail page. Kept separate from staff.js on purpose: the
// barangay screens and the city detail page must not share action wiring.
import './cityadmin-shelter.js';
// Shelter transfers (Phase 2 item 8). Loaded on every staff page: the four
// modals appear on both Transfers pages AND on both shelter pages, and the file
// no-ops when window.TransferConfig is absent.
import './transfers.js';
// PHASE 6 item 3. Steps the font size down on cells marked data-fit when a
// table would otherwise be wider than its panel. A module, not a Vite entry:
// vite.config.js is unchanged, and public.js imports the same file so the
// citizen tables behave identically. No-ops when no data-fit cell is present.
import { initTextFit } from './text-fit';

initFormHygiene();
initTextFit();
