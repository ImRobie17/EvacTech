import assert from 'node:assert/strict';
import { initReportFilters } from '../../resources/js/report-filters.js';

class Field {
  constructor(value = '') { this.value = value; this.disabled = false; }
}
class Section {
  constructor(fields = []) { this.fields = fields; this.hidden = false; this.style = {}; this.open = false; this.textContent = ''; }
  querySelectorAll(selector) { return selector === 'select' ? this.fields : this.fields; }
}
const defs = {
  relief_received: { date_basis: 'Receipt date', demographics: 'none' },
  relief: { date_basis: 'Distribution date', demographics: 'advanced' },
  demographics: { date_basis: 'Registration date', demographics: 'direct' },
  occupancy: { date_basis: null, demographics: 'none' },
  shelter_ranking: { date_basis: null, demographics: 'none', center: false },
};
const from = new Field('2026-10-01');
const sex = new Field('');
const center = new Field('1');
const elements = {
  '[data-report-dates]': new Section([from]),
  '[data-report-demographics]': new Section([sex]),
  '[data-report-center]': new Section([center]),
  '[data-report-description]': { textContent: '' },
  '[data-report-date-note]': { textContent: '' },
  '[data-report-demographics-summary]': { textContent: '' },
  '[data-report-demographics-note]': { textContent: '' },
};
const type = {
  value: 'relief_received', listener: null,
  addEventListener(event, fn) { assert.equal(event, 'change'); this.listener = fn; },
  change(value) { this.value = value; this.listener(); },
};
const form = {
  dataset: { reportDefinitions: JSON.stringify(defs) },
  querySelector(q) { return q === '[name="report_type"]' ? type : elements[q]; },
};
const root = { querySelectorAll() { return [form]; } };
initReportFilters(root);
assert.equal(elements['[data-report-demographics]'].hidden, true);
assert.equal(sex.disabled, true);
assert.equal(from.disabled, false);
type.change('demographics');
assert.equal(sex.disabled, false);
assert.equal(elements['[data-report-demographics]'].open, true);
sex.value = 'male';
type.change('relief');
assert.equal(sex.disabled, false);
assert.equal(elements['[data-report-demographics]'].open, true);
assert.match(elements['[data-report-demographics-summary]'].textContent, /Advanced/);
type.change('occupancy');
assert.equal(from.disabled, true);
assert.equal(from.value, '');
assert.equal(sex.disabled, true);
assert.equal(sex.value, '');
type.change('shelter_ranking');
assert.equal(center.disabled, true);
assert.equal(center.value, '');
console.log('Report filter smoke checks: PASS');
