// ---------------------------------------------------------------------
// Relief distribution: household composition + suggestion notes
// ---------------------------------------------------------------------
// DROP B2.
//
// WHY THIS IS A SHARED MODULE. Both Distribute modals need it -- the Camp
// Manager one in staff.js and the CSWD Office one in cityadmin-shelter.js --
// and those are two separate entry modules with no shared scope. Writing the
// rules out twice is how the two distribute CONTROLLERS came to disagree for a
// whole phase. One definition, imported twice.
//
// NO AI, NO MODEL, NO NETWORK CALL. Every figure and every note below is
// derived from the household payload the modal has already fetched. The rules
// are a fixed table written once and read the same way every time, which is the
// property that matters: the same family always produces the same note, and any
// note on screen can be traced to a line in this file.
//
// The payloads from Barangay\EvacueeProfilingController::show() and
// CityAdmin\ShelterDetailController::household() carry an IDENTICAL member
// shape -- id, full_name, sex, is_head, is_present, age_tier, age_tier_label,
// tags[{id, code, name}] -- which is what makes one module possible. If either
// payload ever drops is_present or age_tier this degrades to fewer facts rather
// than throwing; see buildFacts().

const TAG = '[EvacTech/relief-composition]';

/* Age tiers that carry a distinct relief implication. Keys are AgeTier's
   constants -- keyed on the CODE, never the label, same rule the vulnerable
   classifications follow. */
const INFANT_TIERS = ['infant', 'toddler'];
const SENIOR_TIERS = ['senior'];

/* The suggestion table. Each entry: when `test` is true of the household,
   show `note`.

   DELIBERATELY PHRASED AS OBSERVATIONS, NOT INSTRUCTIONS. "3 infants present"
   is a fact the operator can check against the family in front of them.
   "Issue 3 milk packs" is an order from a system that cannot see the shelf, and
   an operator who follows it when it is wrong has been misled by their own
   tool. Anything on this screen will be read as authoritative, so it says only
   what it actually knows. */
const SUGGESTION_RULES = [
    {
        test: (c) => c.infants > 0,
        note: (c) => `${c.infants} infant or toddler present -- milk, diapers and clean water are usually needed and are not in a standard food pack.`,
    },
    {
        test: (c) => c.seniors > 0,
        note: (c) => `${c.seniors} senior citizen present -- ask about maintenance medicine, which cannot be issued from stock and needs a special item request.`,
    },
    {
        test: (c) => c.tagCodes.has('pregnant') || c.tagCodes.has('lactating'),
        note: () => 'A pregnant or lactating member is tagged -- supplementary food and extra water are usually appropriate.',
    },
    {
        test: (c) => c.tagCodes.has('pwd'),
        note: () => 'A member is tagged PWD -- check whether assistive items are needed; these go through a special item request, not stock.',
    },
    {
        test: (c) => c.tagCodes.has('solo_parent'),
        note: () => 'Solo parent household -- there may be nobody able to queue a second time.',
    },
    {
        test: (c) => c.present > 0 && c.present < c.total,
        note: (c) => `Only ${c.present} of ${c.total} members are currently checked in. Issue for who is present, not for the whole household roster.`,
    },
    {
        test: (c) => c.present === 0,
        note: () => 'Nobody in this household is marked present. Confirm who is actually collecting before issuing.',
    },
];

/**
 * Reduce a household payload to the handful of counts the panel reports.
 *
 * Every field is read defensively. A missing members array or a member without
 * age_tier yields a smaller set of facts, never a thrown error -- gotcha 28:
 * one bad lookup in a fill routine silently abandons everything after it, and
 * this runs immediately before the operator is meant to type quantities.
 */
export function summariseHousehold(data) {
    const members = Array.isArray(data?.members) ? data.members : [];
    const present = members.filter((m) => m && m.is_present);

    const tagCodes = new Set();
    const tagNames = new Set();
    present.forEach((m) => {
        (Array.isArray(m.tags) ? m.tags : []).forEach((t) => {
            if (t && t.code) tagCodes.add(t.code);
            if (t && t.name) tagNames.add(t.name);
        });
    });

    return {
        total: members.length,
        present: present.length,
        /* Counted over PRESENT members only. A senior who is not at the shelter
           should not generate a note about medicine for someone standing at the
           table. */
        infants: present.filter((m) => INFANT_TIERS.includes(m.age_tier)).length,
        seniors: present.filter((m) => SENIOR_TIERS.includes(m.age_tier)).length,
        tagCodes,
        tagNames,
    };
}

/**
 * Render the composition panel and its notes into a container.
 *
 * `container` is expected to hold two children, marked with the data
 * attributes below. Both are looked up defensively; if the markup is missing,
 * this logs a specific reason and returns rather than half-rendering.
 */
export function renderComposition(container, data) {
    if (!container) return;

    const factsEl = container.querySelector('[data-composition-facts]');
    const notesEl = container.querySelector('[data-composition-notes]');

    if (!factsEl || !notesEl) {
        console.error(`${TAG} container is missing [data-composition-facts] or [data-composition-notes]; panel not rendered.`);
        return;
    }

    const c = summariseHousehold(data);

    // ---- Facts ----
    const facts = [
        `${c.present} of ${c.total} members checked in`,
    ];
    if (c.infants > 0) facts.push(`${c.infants} infant/toddler`);
    if (c.seniors > 0) facts.push(`${c.seniors} senior citizen`);
    facts.push(c.tagNames.size ? `Tags: ${[...c.tagNames].join(', ')}` : 'No vulnerable classifications tagged');

    factsEl.innerHTML = '';
    facts.forEach((text) => {
        const li = document.createElement('li');
        // textContent, not innerHTML: names and tag labels are user data.
        li.textContent = text;
        factsEl.appendChild(li);
    });

    // ---- Notes ----
    const notes = SUGGESTION_RULES.filter((r) => {
        try {
            return r.test(c);
        } catch (err) {
            console.error(`${TAG} a suggestion rule threw and was skipped.`, err);
            return false;
        }
    }).map((r) => r.note(c));

    notesEl.innerHTML = '';
    if (notes.length === 0) {
        /* An empty panel that simply vanishes makes "nothing to flag" and "this
           feature is broken" look identical. It says which one it is. */
        const p = document.createElement('p');
        p.className = 'empty-note';
        p.textContent = 'Nothing specific to flag for this family.';
        notesEl.appendChild(p);
    } else {
        const ul = document.createElement('ul');
        notes.forEach((text) => {
            const li = document.createElement('li');
            li.textContent = text;
            ul.appendChild(li);
        });
        notesEl.appendChild(ul);
    }

    container.hidden = false;
}
