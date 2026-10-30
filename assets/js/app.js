// PesaGuard client-side helpers
document.addEventListener('DOMContentLoaded', function () {
    // Ask before destructive actions (forms or buttons with data-confirm)
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('submit', function (ev) {
            if (!window.confirm(el.getAttribute('data-confirm'))) { ev.preventDefault(); }
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
