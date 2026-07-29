/**
 * Phase 2 item 5 -- the seven CSWDO age tiers, client side.
 *
 * The mirror of app/Support/AgeTier.php. The boundaries and the month
 * arithmetic below are deliberately identical to the PHP and to MySQL's
 * TIMESTAMPDIFF(MONTH, ...), so the badge a staff member sees while typing and
 * the tier the server stores can never disagree at a boundary.
 *
 * Imported by staff.js, cityadmin.js and cityadmin-shelter.js, all three of
 * which are pulled in by app.js -- so this needs no new Vite entry.
 *
 * Nothing here is authoritative. The server recomputes the tier on every read;
 * this only drives the on-screen badge and the age-group dropdown default.
 */

/** Upper bound in MONTHS, exclusive, ascending. Mirrors AgeTier::BOUNDS. */
export const AGE_TIERS = [
    { key: 'infant', under: 7, label: 'Infant (0-6 months)', short: 'Infant' },
    { key: 'toddler', under: 36, label: 'Toddler (7 months-2 years)', short: 'Toddler' },
    { key: 'preschool', under: 72, label: 'Pre-school (3-5 years)', short: 'Pre-school' },
    { key: 'school_age', under: 156, label: 'School Age (6-12 years)', short: 'School Age' },
    { key: 'teenage', under: 216, label: 'Teenage (13-17 years)', short: 'Teenage' },
    { key: 'adult', under: 720, label: 'Adult (18-59 years)', short: 'Adult' },
    { key: 'senior', under: Infinity, label: 'Senior Citizen (60+ years)', short: 'Senior Citizen' },
];

const SHORT = AGE_TIERS.reduce((acc, t) => { acc[t.key] = t.short; return acc; }, { unknown: 'Unknown' });

/**
 * Whole months elapsed, computed exactly the way MySQL TIMESTAMPDIFF(MONTH)
 * and AgeTier::monthsSince() do it. Not a day-count divided by 30.44.
 */
export function monthsSince(dateStr) {
    if (!dateStr) return null;

    const parts = String(dateStr).split('-');
    if (parts.length !== 3) return null;

    const year = Number(parts[0]);
    const month = Number(parts[1]);
    const day = Number(parts[2]);
    if (!year || !month || !day) return null;

    const today = new Date();
    let months = (today.getFullYear() - year) * 12 + (today.getMonth() + 1 - month);

    // Day-of-month not yet reached, so the final month is incomplete.
    if (today.getDate() < day) months--;

    return months < 0 ? null : months;
}

export function tierFromMonths(months) {
    if (months === null || months === undefined || months < 0) return 'unknown';
    const match = AGE_TIERS.find((t) => months < t.under);
    return match ? match.key : 'senior';
}

export function tierFromBirthdate(dateStr) {
    return tierFromMonths(monthsSince(dateStr));
}

export function tierShortLabel(key) {
    return SHORT[key] || SHORT.unknown;
}

/** "3 yrs" / "5 mos" -- months only matter for the two youngest tiers. */
export function ageDisplay(months) {
    if (months === null || months === undefined) return '';
    if (months < 24) return months + (months === 1 ? ' mo' : ' mos');
    return Math.floor(months / 12) + ' yrs';
}

/**
 * Wire one member row's birthdate field to its age-group dropdown and badge.
 *
 * The rule, matching the server: a birthdate ALWAYS wins. When one is present
 * the dropdown is set from it and locked, because letting staff pick a group
 * that contradicts the birthday they just typed would only produce a value the
 * server throws away. Clearing the birthdate unlocks the dropdown for the fast
 * tag-first path.
 *
 * A disabled <select> is not submitted, which is exactly right: with a
 * birthdate present the server ignores age_group anyway, and the validation
 * rule is required_without:birthdate.
 */
export function wireAgeGroup(row) {
    const birthdate = row.querySelector('[data-field="birthdate"]');
    const group = row.querySelector('[data-field="age_group"]');
    const badge = row.querySelector('[data-age-tag]');
    if (!birthdate || !group) return;

    function apply() {
        const months = monthsSince(birthdate.value);

        if (months !== null) {
            const key = tierFromMonths(months);
            group.value = key;
            group.disabled = true;
            group.setAttribute('aria-describedby', 'derived from date of birth');
            if (badge) {
                badge.hidden = false;
                badge.textContent = tierShortLabel(key) + ' (' + ageDisplay(months) + ')';
            }
        } else {
            group.disabled = false;
            group.removeAttribute('aria-describedby');
            if (badge) {
                if (group.value) {
                    badge.hidden = false;
                    badge.textContent = tierShortLabel(group.value) + ' (stated)';
                } else {
                    badge.hidden = true;
                }
            }
        }
    }

    birthdate.addEventListener('change', apply);
    birthdate.addEventListener('input', apply);
    group.addEventListener('change', apply);
    apply();

    return apply;
}
