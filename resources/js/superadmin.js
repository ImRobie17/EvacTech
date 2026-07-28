// Super Admin behaviour.
//
// WHY THIS FILE EXISTS (Chat C)
// ----------------------------
// This was ~60 lines of inline <script> at the bottom of
// superadmin/users/index.blade.php -- the only role in the codebase whose
// behaviour did not live in a bundle. That caused a real, visible bug.
//
// app.js imports cityadmin.js on EVERY page. cityadmin.js's initUserAdmin()
// guarded only on `#userModal` existing, and the Super Admin users page happens
// to use the very same element ids (userModal, userForm, userMethod, u-name,
// u-status-field, u-pw-hint, and the [data-edit-user] buttons). So City Admin's
// handlers bound themselves to Super Admin's buttons. Both then ran on click,
// and because a bundled module executes AFTER an inline script at the end of
// body, City Admin's DOMContentLoaded listener was registered second and won:
// "+ Add User" opened a dialog headed "Add Barangay Personnel".
//
// The fix is structural rather than defensive. This file is a separate Vite
// entry that only layouts/superadmin opts into, so the two never load together.
// The id check below is a second, independent line of defence: each module
// refuses to touch a form that is not its own, so re-introducing a shared
// bundle later cannot silently resurrect the same bug.
//
// Nothing else about the page's behaviour changed. The role/barangay
// show-hide, the create-vs-edit switching and the password hint all work as
// they did, with the fixes noted inline.

const TAG = '[EvacTech/superadmin]';

console.info(TAG + ' loaded');

function initUserAdmin() {
    const modal = document.getElementById('userModal');
    if (!modal) return;

    // Second line of defence -- see the header note. The City Admin user modal
    // carries data-user-form="city" and is handled by cityadmin.js.
    if (modal.dataset.userForm !== 'super') return;

    const form = document.getElementById('userForm');
    const title = document.getElementById('userModalTitle');
    const submit = document.getElementById('userSubmit');
    const methodInput = document.getElementById('userMethod');
    const statusField = document.getElementById('u-status-field');
    const roleSelect = document.getElementById('u-role');
    const barangayField = document.getElementById('u-barangay-field');
    const barangaySelect = document.getElementById('u-barangay');
    const pw = document.getElementById('u-password');
    const pwHint = document.getElementById('u-pw-hint');

    if (!form || !roleSelect || !barangayField) {
        console.error(TAG + ' the user modal is missing expected fields.');
        return;
    }

    const storeUrl = form.getAttribute('action');

    // Barangay only applies to barangay personnel. This used to set
    // style.display directly, which cannot be undone by a stylesheet and is
    // invisible to anything that inspects the DOM for hidden content. The
    // `hidden` attribute is what the rest of the codebase uses, and
    // design-system.css already enforces [hidden] { display: none !important }.
    //
    // The select is also disabled when hidden, so a barangay id left over from a
    // previous edit is not submitted for a City Admin account.
    function syncBarangay() {
        const isPersonnel = roleSelect.value === 'barangay_personnel';
        barangayField.hidden = !isPersonnel;
        if (barangaySelect) {
            barangaySelect.disabled = !isPersonnel;
            if (!isPersonnel) barangaySelect.value = '';
        }
    }

    roleSelect.addEventListener('change', syncBarangay);

    document.querySelectorAll('[data-open-modal="userModal"]').forEach((btn) => {
        btn.addEventListener('click', () => {
            form.reset();
            form.action = storeUrl;
            methodInput.value = 'POST';
            title.textContent = 'Add User';
            submit.textContent = 'Create Account';
            statusField.hidden = true;
            pwHint.textContent = '(min 8 characters)';
            pw.required = true;
            syncBarangay();
        });
    });

    document.querySelectorAll('[data-edit-user]').forEach((btn) => {
        btn.addEventListener('click', () => {
            let d;
            try {
                d = JSON.parse(btn.dataset.editUser);
            } catch (err) {
                // A name containing an unusual character used to be able to
                // break this silently, leaving Edit doing nothing at all.
                console.error(TAG + ' could not read the user payload.', err);
                return;
            }

            form.reset();
            form.action = d.update_url;
            methodInput.value = 'PUT';
            title.textContent = 'Edit User';
            submit.textContent = 'Save Changes';
            statusField.hidden = false;
            pwHint.textContent = '(leave blank to keep current)';
            pw.required = false;

            document.getElementById('u-name').value = d.name || '';
            document.getElementById('u-email').value = d.email || '';
            document.getElementById('u-contact').value = d.contact_number || '';
            roleSelect.value = d.role || 'barangay_personnel';

            // Order matters: syncBarangay() re-enables the select for barangay
            // personnel, so the value has to be written AFTER it, or it lands on
            // a disabled control and is dropped on submit.
            syncBarangay();
            if (barangaySelect) barangaySelect.value = d.barangay_id || '';

            document.getElementById('u-status').value = d.status || 'active';

            // staff.js owns modal open/close (focus handling, Escape, backdrop
            // click). Setting .hidden directly, as the inline version did,
            // skipped all of it.
            modal.hidden = false;
            document.getElementById('u-name')?.focus();
        });
    });

    syncBarangay();
}

document.addEventListener('DOMContentLoaded', () => {
    initUserAdmin();
});
