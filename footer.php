<footer class="site-footer" aria-label="Oldal l&aacute;bl&eacute;c">
    <div class="site-footer__inner">
        <div class="site-footer__links">
            <span>|</span>
            <button type="button" class="site-footer__link" onclick="footerOpenInfoModal('hasznalati')">Haszn&aacute;lati &uacute;tmutat&oacute;</button>
        </div>
        <div class="site-footer__links">
            <button type="button" class="site-footer__link" onclick="footerOpenInfoModal('gyik')">GYIK</button>
            <span>|</span>
            <button type="button" class="site-footer__link" onclick="footerOpenInfoModal('kapcsolat')">Kapcsolat</button>
        </div>
        <div class="site-footer__links">
            <a class="site-footer__link" href="/dokumentumok/aszf.pdf" download>&Aacute;SZF</a>
            <span>|</span>
            <a class="site-footer__link" href="/dokumentumok/adatvedelmi_nyilatkozat.pdf" download>Adatv&eacute;delmi nyilatkozat</a>
        </div>
        <div class="site-footer__copy">2026 &copy; SHOBID.com</div>
    </div>
</footer>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var fieldSelectors = [
        '.create-field',
        '.auth-field',
        '.profile-formgrid > div',
        '.admin-panel .create-field',
        '.admin-settings-card',
        '.profile-settings-card .create-field'
    ];

    var fields = document.querySelectorAll(fieldSelectors.join(','));
    fields.forEach(function (field) {
        var mainLabel = field.querySelector(':scope > label:not(.shipping-choice):not(.create-filepicker):not(.admin-checkbox)');
        if (!mainLabel) return;

        var requiredInput = field.querySelector('input[required], select[required], textarea[required]');
        if (!requiredInput) return;

        mainLabel.classList.add('required-label');
    });
});

function footerOpenInfoModal(modalType) {
    if (typeof openSidebarInfoModal === 'function') {
        openSidebarInfoModal(modalType);
    }
}
</script>
