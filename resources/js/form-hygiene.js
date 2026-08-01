// ---------------------------------------------------------------------
// Form hygiene: whitespace-only input must not satisfy `required`
// ---------------------------------------------------------------------
// PHASE 4 (item 15a). THE BUG THIS FIXES:
//
// Typing three spaces into a required field satisfies the browser's `required`
// check, because " " is not the empty string. The form therefore submits, the
// page navigates, THE MODAL DISAPPEARS along with everything else the operator
// typed, and Laravel's TrimStrings middleware turns "   " into null -- so the
// server correctly rejects it and bounces back with "The address field is
// required" in the banner at the top of the page. Correct outcome, terrible
// experience: a full round trip and lost work to report a typo.
//
// Trimming the field client side makes it GENUINELY empty, so the browser's own
// constraint validation blocks the submit before it starts. No navigation, so
// the modal stays open, every other field keeps its value, and the message
// appears in a bubble anchored to the offending field.
//
// The server side needs no change. TrimStrings + ConvertEmptyStringsToNull are
// both still in Laravel's default stack (bootstrap/app.php does not remove
// them), so whitespace has always been rejected -- it was only ever the timing
// and the presentation that were wrong.
//
// Bound by delegation on document, per project convention. There is no config
// object to be absent and no element to look up at load time, so there is no
// silent-failure path to report: the listeners attach or the file did not load
// at all, which the console will say for us.

// focusout, not blur: blur does not bubble, so a delegated listener on document
// would never see it. Clicking a submit button moves focus out of the field
// first, which is why the common case is covered without touching submit.
const TRIMMABLE_INPUT_TYPES = ['text', 'search', 'email', 'tel', 'url'];

function isTrimmable(el) {
    if (!el) return false;

    // Never touch a password. Leading or trailing spaces can be deliberate, and
    // silently rewriting one turns a correct login into a failed login with no
    // visible cause.
    if (el.type === 'password') return false;

    // An explicit opt-out, in case a future field genuinely needs its spaces.
    if (el.hasAttribute('data-no-trim')) return false;

    if (el.tagName === 'TEXTAREA') return true;
    if (el.tagName !== 'INPUT') return false;

    // el.type is normalised by the browser, so a missing type attribute reads as
    // "text" and is covered. number, date, checkbox and hidden are not listed
    // and are left alone.
    return TRIMMABLE_INPUT_TYPES.includes(el.type);
}

function trimField(el) {
    if (!isTrimmable(el)) return;

    const trimmed = el.value.trim();
    if (trimmed === el.value) return;

    el.value = trimmed;

    // Anything listening for input on this field -- the surname prefill on the
    // evacuee forms, the search-as-you-type boxes -- must see the corrected
    // value, not the one with the spaces.
    el.dispatchEvent(new Event('input', { bubbles: true }));
}

export function initFormHygiene() {
    document.addEventListener('focusout', (e) => trimField(e.target));

    // Pressing Enter inside a text field submits the form WITHOUT a focusout,
    // so the field would still hold its spaces when the browser validated it.
    // keydown runs before the submit is raised, which is early enough. The
    // whole form is trimmed, not just the focused field, because Enter can be
    // pressed while sitting in a field that is already clean.
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter') return;
        if (e.target.tagName === 'TEXTAREA') return; // Enter is a newline here

        const form = e.target.form;
        if (!form) return;

        Array.from(form.elements).forEach(trimField);
    });
}
