/*
 * app.js: small browser-side helpers, loaded on every page.
 *  1. Ask "Are you sure?" before deleting.
 *  2. Lock a form after the first click, so a slow action is not repeated.
 *  3. Upload page: hide the password box when a CSV file is chosen.
 *  4. Check that the two passwords match before the form is sent.
 * The server repeats every important check; these only make the pages friendlier.
 */
// Run after the page has finished loading, so every element exists.
document.addEventListener('DOMContentLoaded', function () {
    // Ask before destructive actions (forms or buttons with data-confirm)
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('submit', function (ev) {
            if (!window.confirm(el.getAttribute('data-confirm'))) { ev.preventDefault(); }
        });
    });

    // Stop double submits (a second click used to repeat a slow action): show a spinner and lock the button
    document.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            if (ev.defaultPrevented) { return; }
            if (form.dataset.busy === '1') { ev.preventDefault(); return; }
            form.dataset.busy = '1';
            var btn = ev.submitter;
            if (btn && btn.tagName === 'BUTTON') {
                if (btn.name) {                      // keep the button's value in the request
                    var h = document.createElement('input');
                    h.type = 'hidden'; h.name = btn.name; h.value = btn.value;
                    form.appendChild(h);
                }
                if (form.method.toLowerCase() === 'post') {
                    btn.insertAdjacentHTML('afterbegin', '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>');
                }
                setTimeout(function () { btn.disabled = true; }, 0);
            }
        });
    });
    window.addEventListener('pageshow', function () {  // coming back with the Back button
        document.querySelectorAll('form[data-busy]').forEach(function (f) {
            f.dataset.busy = '';
            f.querySelectorAll('button:disabled').forEach(function (b) { b.disabled = false; });
            f.querySelectorAll('.spinner-border').forEach(function (s) { s.remove(); });
        });
    });

    // Upload form: show the password field only for PDF files
    var fileInput = document.getElementById('statement_file');
    var pwdGroup = document.getElementById('pdf-password-group');
    if (fileInput && pwdGroup) {
        var toggle = function () {
            var name = (fileInput.files[0] && fileInput.files[0].name || '').toLowerCase();
            pwdGroup.style.display = name.endsWith('.csv') ? 'none' : '';
        };
        fileInput.addEventListener('change', toggle);
        toggle();
    }

    // Client-side check that the two passwords match on registration / profile
    document.querySelectorAll('form[data-match-passwords]').forEach(function (form) {
        form.addEventListener('submit', function (ev) {
            var p1 = form.querySelector('[name=password]'), p2 = form.querySelector('[name=confirm_password]');
            if (p1 && p2 && p1.value !== p2.value) {
                ev.preventDefault();
                p2.setCustomValidity('Passwords do not match');
                p2.reportValidity();
                p2.addEventListener('input', function () { p2.setCustomValidity(''); }, { once: true });
            }
        });
    });
});
