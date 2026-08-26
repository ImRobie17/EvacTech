/* =========================================================================
   EvacTech -- sex-specific vulnerable categories (Phase 7 item 2).

   Pregnant Woman and Lactating Mother are shown only when the member's sex is
   Female.

   ONE MODULE, THREE TEMPLATES
   ---------------------------
   Phase 6 unified the barangay member-row markup into partials/evacuee-modal,
   but City Admin still has two of its own: #ceMemberRowTemplate in
   cityadmin/evacuees/index and #cdMemberRowTemplate in cityadmin/shelters/show.
   Three templates, three different row builders (staff.js, cityadmin.js,
   cityadmin-shelter.js).

   Rather than write the same toggle three times, this listens once on
   `document`. app.js imports all three of those files on every staff page, so a
   single delegated listener reaches every member row in the system regardless
   of which module cloned it. Nothing here knows which screen it is on.

   WHY THE DEFAULT STATE LIVES IN THE MARKUP
   -----------------------------------------
   The two labels are rendered with the `hidden` attribute already set. A fresh
   row has no sex chosen, and the correct state for "no sex chosen" is hidden --
   so the server-rendered HTML is already right and this module never has to run
   an initial pass over rows it did not see created. Delegation cannot observe a
   row being cloned into the DOM; it can only observe events. Putting the
   default in the template removes the need to observe anything.

   The escape hatch for a POPULATED row (editing an existing female member) is
   that the three row builders dispatch a bubbling `change` on the sex select
   right after they set its value. That is one line each and it routes through
   exactly the same code path a human click takes.

   `hidden` is reliable here: design-system.css restates
   `[hidden] { display: none !important }` on purpose, so it beats the
   inline-flex utility on the label.
   ========================================================================= */

export function initSexFields() {
    document.addEventListener('change', (e) => {
        const select = e.target;
        if (!select.matches || !select.matches('[data-field="sex"]')) return;

        const row = select.closest('[data-row]');
        if (!row) {
            // Every member row in the app carries data-row. If this fires, a
            // template has been edited and the sex select now sits outside it.
            console.warn('EvacTech sex-fields: a [data-field="sex"] control has no [data-row] ancestor; categories were left as they are.');
            return;
        }

        applyToRow(row);
    });
}

/**
 * Show or hide the female-only categories in one member row.
 *
 * Unticking on hide is deliberate and not merely tidy. Without it, an operator
 * who tags a woman as pregnant and then corrects the sex to male leaves a
 * ticked-but-invisible checkbox in the form, which still posts. The server
 * rejects it (MemberRules::tags), so the operator would get a validation error
 * pointing at a control they can no longer see.
 */
function applyToRow(row) {
    const isFemale = row.querySelector('[data-field="sex"]')?.value === 'female';

    row.querySelectorAll('[data-female-only]').forEach((wrapper) => {
        wrapper.hidden = !isFemale;

        if (!isFemale) {
            const box = wrapper.querySelector('[data-field="tags"]');
            if (box) box.checked = false;
        }
    });
}
