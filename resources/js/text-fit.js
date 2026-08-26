/* =========================================================================
   EvacTech -- dynamic text scaling for table cells (Phase 6 item 3).

   THE PROBLEM THIS SOLVES
   -----------------------
   A table column has no width of its own. With `table-layout: auto` the browser
   sizes each column to its content, so one unusually long value -- a long
   household head name, a full street address, a shelter name -- does not
   overflow its cell. It widens the whole table instead, and every other column
   is squeezed to pay for it. That is what produced the check-in timestamp
   wrapping to six lines while Actions ran off the panel.

   WHY OVERFLOW DETECTION ON THE CELL DOES NOT WORK
   ------------------------------------------------
   The obvious implementation -- compare a cell's scrollWidth to its clientWidth
   -- always reports no overflow, because the cell grew to fit. The measurement
   that means anything is one level up: is the TABLE wider than the panel that
   holds it. `.table-panel` is `overflow-x: auto`, so when the table does not
   fit, `table.scrollWidth` exceeds `wrap.clientWidth`. That is the trigger.

   WHAT IT DOES
   ------------
   For any table containing cells marked `data-fit`, if the table is too wide
   for its panel, the font size of those cells is stepped down one notch and the
   table is measured again. At most two steps, and never below the floor.

   THE FLOOR IS NOT NEGOTIABLE
   ---------------------------
   17px base, then 16px, then 15px, and it stops. The accessibility rules for
   this project set a 17px base for a reason: this runs on cheap phones and
   shared desktops in an emergency, read by people who are not having a good
   day. Two steps is a nudge. Anything more is a readability problem wearing a
   layout problem's clothes -- if a table still does not fit at 15px, it needs
   fewer columns, not smaller type.

   WHERE TO PUT data-fit
   ---------------------
   On the CELL, and only on cells holding free text that can be genuinely long:
   names, addresses, shelter names, email addresses. Never on a cell containing
   a badge, a button or a link -- shrinking those would undercut the 44px tap
   target rule, and a status label has to stay legible at a glance.

   IF NOTHING HAPPENS
   ------------------
   Check the console. Every path that gives up says why.
   ========================================================================= */

const TAG = '[EvacTech/text-fit]';

/* The full ladder, largest first. Index 0 is the normal --text-base value, so
   applying step 0 is the same as applying nothing. Kept as explicit pixel
   values rather than rem multipliers so the floor is readable at a glance and
   cannot drift if a root font size changes elsewhere. */
const STEPS = ['17px', '16px', '15px'];

/* Below this width the responsive tables stack into cards, where nothing is in
   a horizontal row and there is nothing to shrink. Matches the breakpoint in
   staff.css. */
const STACK_BREAKPOINT = 768;

console.info(TAG + ' loaded');

function applyStep(cells, step) {
    cells.forEach((cell) => {
        if (step === 0) {
            cell.style.removeProperty('font-size');
        } else {
            cell.style.fontSize = STEPS[step];
        }
    });
}

function fitTable(table) {
    const cells = Array.from(table.querySelectorAll('[data-fit]'));
    if (cells.length === 0) return;

    /* The scrolling container. .table-panel is the normal case; the parent is a
       fallback for a table placed somewhere else. Without one there is nothing
       to measure against and shrinking would be guesswork. */
    const wrap = table.closest('.table-panel') || table.parentElement;
    if (!wrap) {
        console.warn(TAG + ' a table has data-fit cells but no scrolling container to measure against; leaving it alone.');
        return;
    }

    // Always start from full size, or a window being widened again would keep
    // whatever size it was shrunk to at its narrowest.
    applyStep(cells, 0);

    if (window.innerWidth < STACK_BREAKPOINT) return;

    let step = 0;
    // +1 absorbs sub-pixel rounding, which otherwise reports a permanent
    // one-pixel overflow and shrinks tables that already fit.
    while (table.scrollWidth > wrap.clientWidth + 1 && step < STEPS.length - 1) {
        step += 1;
        applyStep(cells, step);
    }

    if (step === STEPS.length - 1 && table.scrollWidth > wrap.clientWidth + 1) {
        console.info(TAG + ' a table is still wider than its panel at the ' + STEPS[step] +
            ' floor. It will scroll horizontally, which is the correct outcome -- the fix is fewer columns, not smaller text.');
    }
}

function fitAll() {
    const tables = document.querySelectorAll('table');
    tables.forEach(fitTable);
}

export function initTextFit() {
    if (!document.querySelector('[data-fit]')) return;

    fitAll();

    /* Debounced: a drag-resize fires continuously, and each pass forces a
       layout read. 150ms is below the threshold where a person notices the
       delay and well above the rate a resize fires at. */
    let timer = null;
    window.addEventListener('resize', () => {
        window.clearTimeout(timer);
        timer = window.setTimeout(fitAll, 150);
    });
}
