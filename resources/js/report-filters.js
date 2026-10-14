/** Standard report generators; leaves the dedicated IDP form untouched. */
export function initReportFilters(root = document) {
    root.querySelectorAll('[data-report-generator]').forEach((form) => {
        const type = form.querySelector('[name="report_type"]');
        const definitions = JSON.parse(form.dataset.reportDefinitions || '{}');
        const dates = form.querySelector('[data-report-dates]');
        const demographics = form.querySelector('[data-report-demographics]');
        const scope = form.querySelector('[data-report-center]');
        const stockFilter = form.querySelector('[data-report-stock-filter]');
        const transferFilter = form.querySelector('[data-report-transfer-filter]');
        const note = form.querySelector('[data-report-description]');
        const dateNote = form.querySelector('[data-report-date-note]');
        const summary = form.querySelector('[data-report-demographics-summary]');
        const filterNote = form.querySelector('[data-report-demographics-note]');

        const setEnabled = (section, enabled) => {
            if (!section) return;
            section.hidden = !enabled;
            section.style.display = enabled ? '' : 'none';
            section.querySelectorAll('input, select, textarea').forEach((control) => {
                control.disabled = !enabled;
                if (!enabled) control.value = '';
            });
        };

        const update = (first = false) => {
            const entry = definitions[type.value];
            if (!entry) return;
            const dateEnabled = entry.date_basis !== null;
            const memberEnabled = entry.demographics !== 'none';
            setEnabled(dates, dateEnabled);
            setEnabled(demographics, memberEnabled);
            setEnabled(scope, entry.center !== false);
            setEnabled(stockFilter, type.value === 'relief_inventory');
            setEnabled(transferFilter, type.value === 'shelter_transfers');

            if (note) note.textContent = entry.description;
            if (dateNote) dateNote.textContent = dateEnabled
                ? `Date filter applies to: ${entry.date_basis}. An empty range includes all records.`
                : 'Current snapshot: no historical date filter is available.';
            if (demographics && memberEnabled) {
                const direct = entry.demographics === 'direct';
                const hasSelection = Array.from(demographics.querySelectorAll('select')).some((el) => el.value);
                demographics.open = direct || hasSelection;
                if (summary) summary.textContent = direct ? 'Demographic filters (optional)' : 'Advanced recipient / household filters (optional)';
                if (filterNote) filterNote.textContent = direct
                    ? (type.value === 'shelter_demographic_summary'
                        ? 'Sex and age narrow the population being counted. Category selects a breakdown row; vulnerability categories may overlap.'
                        : 'These filters narrow the individual member records included.')
                    : 'Shows households containing matching members, or distributions to those households. Household sizes and relief quantities are not recalculated.';
            }
        };
        type.addEventListener('change', () => update());
        update(true);
    });
}
