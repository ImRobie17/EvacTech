import './bootstrap';
import './staff.js';
import './cityadmin.js';
// City Admin shelter detail page. Kept separate from staff.js on purpose: the
// barangay screens and the city detail page must not share action wiring.
import './cityadmin-shelter.js';
// Shelter transfers (Phase 2 item 8). Loaded on every staff page: the four
// modals appear on both Transfers pages AND on both shelter pages, and the file
// no-ops when window.TransferConfig is absent.
import './transfers.js';
