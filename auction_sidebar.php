<?php
$sidebarUserData = $sidebarUser ?? ($user ?? null);
$sidebarSearchValue = $keresesRaw ?? '';
$sidebarCurrentKat = $aktualisKatKod ?? '';
$sidebarMostDb = intval($mostMegyDb ?? 0);
$sidebarEloDb = intval($eloDb ?? 0);
$sidebarKozelgoDb = intval($kozelgoDb ?? 0);
$sidebarKuponDb = intval($kuponDb ?? 0);
$sidebarKategoriak = $kategoriak ?? [];
$sidebarKategoriakEloDb = $kategoriakEloDb ?? [];
$sidebarMostActive = !empty($mostMegyMenuAktiv);
$sidebarEloActive = !empty($eloMenuAktiv);
$sidebarKozelgoActive = !empty($kozelgoMenuAktiv);
$sidebarKuponActive = !empty($kuponMenuAktiv);
$sidebarPage = $sidebarActivePage ?? '';
$sidebarSearchAction = $sidebarSearchAction ?? 'index.php';
$sidebarIndexBase = $sidebarIndexBase ?? 'index.php';
$sidebarShowSearch = array_key_exists('sidebarShowSearch', get_defined_vars()) ? (bool) $sidebarShowSearch : true;
$sidebarFeatured = !empty($sidebarUserData['kiemelt_felhasznalo']);

include_once __DIR__ . '/global/markets.php';
include_once __DIR__ . '/global/i18n.php';
$sidebarMarkets = function_exists('shobidMarketList') ? shobidMarketList() : [];
$sidebarCurrentMarketCode = function_exists('shobidDetectMarketCodeFromRequest') ? shobidDetectMarketCodeFromRequest() : 'hu-hu';
$sidebarCurrentMarket = function_exists('shobidMarketByCode') ? shobidMarketByCode($sidebarCurrentMarketCode) : null;
$sidebarLocaleCode = function_exists('shobidI18nCurrentLocale') ? shobidI18nCurrentLocale() : $sidebarCurrentMarketCode;
if (!$sidebarCurrentMarket) {
    $sidebarCurrentMarket = ['code' => 'hu-hu', 'name' => 'Hungary', 'label' => 'HU-HU', 'available' => true];
}
?>
<aside class="auction-sidebar">
    <div class="auction-sidebar-top">
        <a class="auction-brand" href="index.php">
            <img class="auction-brand__logo" src="/kepek/web_sb_logo.webp" alt="Hard Illustrated">
        </a>

        <?php if ($sidebarShowSearch): ?>
        <form class="auction-search" method="GET" action="<?php echo htmlspecialchars($sidebarSearchAction); ?>">
            <?php if ($sidebarCurrentKat !== ''): ?>
            <input type="hidden" name="kat" value="<?php echo htmlspecialchars($sidebarCurrentKat); ?>">
            <?php endif; ?>
            <input type="text" name="q" placeholder="<?php echo htmlspecialchars(st('sidebar.search_placeholder', 'Kereses', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars($sidebarSearchValue); ?>">
        </form>
        <?php endif; ?>

        <button type="button" class="auction-mobile-menu-toggle" aria-label="<?php echo htmlspecialchars(st('sidebar.menu', 'Menu', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?>" onclick="toggleSidebarMobileMenu(event)">&#8801;</button>
    </div>

    <nav class="auction-sidebar__nav">
        <a class="auction-navlink <?php echo $sidebarMostActive ? 'is-active' : ''; ?>" href="<?php echo htmlspecialchars($sidebarIndexBase); ?>">
            <img class="auction-navlink__icon auction-navlink__icon--image" src="/kepek/icons/fire.webp" alt="">
            <span><?php echo htmlspecialchars(st('sidebar.nav.now', 'Most', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="auction-count-badge"><?php echo $sidebarMostDb; ?></span>
        </a>

        <a class="auction-navlink auction-navlink--live <?php echo $sidebarEloActive ? 'is-active' : ''; ?>" href="<?php echo htmlspecialchars($sidebarIndexBase . '?kat=elo'); ?>">
            <img class="auction-navlink__icon auction-navlink__icon--image auction-navlink__icon--live" src="/kepek/icons/live.webp" alt="">
            <span><?php echo htmlspecialchars(st('sidebar.nav.live', 'Elo', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="auction-count-badge"><?php echo $sidebarEloDb; ?></span>
        </a>

        <a class="auction-navlink <?php echo $sidebarKozelgoActive ? 'is-active' : ''; ?>" href="<?php echo htmlspecialchars($sidebarIndexBase . '?kat=kozelgo'); ?>">
            <img class="auction-navlink__icon auction-navlink__icon--image" src="/kepek/icons/time.webp" alt="">
            <span><?php echo htmlspecialchars(st('sidebar.nav.upcoming', 'Kozelgo', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="auction-count-badge"><?php echo $sidebarKozelgoDb; ?></span>
        </a>

        <a class="auction-navlink <?php echo $sidebarKuponActive ? 'is-active' : ''; ?>" href="<?php echo htmlspecialchars($sidebarIndexBase . '?kat=kupon'); ?>">
            <img class="auction-navlink__icon auction-navlink__icon--image" src="/kepek/icons/kupon.webp" alt="">
            <span><?php echo htmlspecialchars(st('sidebar.nav.coupons', 'Kuponok', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
            <span class="auction-count-badge"><?php echo $sidebarKuponDb; ?></span>
        </a>

        <details class="auction-navgroup">
            <summary class="auction-navlink">
                <img class="auction-navlink__icon auction-navlink__icon--image" src="/kepek/icons/cat.webp" alt="">
                <span><?php echo htmlspecialchars(st('sidebar.nav.categories', 'Kategoriak', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
            </summary>
            <div class="auction-navgroup__items">
                <?php foreach ($sidebarKategoriak as $sidebarKat): ?>
                <a class="auction-sublink <?php echo $sidebarCurrentKat === (string) intval($sidebarKat['id']) ? 'is-active' : ''; ?>" href="<?php echo htmlspecialchars($sidebarIndexBase . '?kat=' . intval($sidebarKat['id'])); ?>">
                    <span><?php echo htmlspecialchars(function_exists('shobidI18nCategoryName') ? shobidI18nCategoryName(intval($sidebarKat['id'] ?? 0), (string)($sidebarKat['nev'] ?? ''), $sidebarLocaleCode) : (string)($sidebarKat['nev'] ?? '')); ?></span>
                    <span class="auction-count-badge"><?php echo intval($sidebarKategoriakEloDb[intval($sidebarKat['id'])] ?? 0); ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </details>

        <?php if (isset($_SESSION['user_id'])): ?>
        <a class="auction-navlink <?php echo $sidebarPage === 'create' ? 'is-active' : ''; ?>" href="uj_aukcio.php">
            <img class="auction-navlink__icon auction-navlink__icon--image" src="/kepek/icons/add.webp" alt="">
            <span><?php echo htmlspecialchars(st('sidebar.nav.new_offer', 'Uj ajanlat', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
        </a>
        <a class="auction-navlink <?php echo $sidebarPage === 'profile' ? 'is-active' : ''; ?>" href="profil.php">
            <?php if (!empty($sidebarUserData['profilkep'])): ?>
            <img class="auction-nav-avatar <?php echo $sidebarFeatured ? 'is-featured-user' : ''; ?>" src="/profilkepek/<?php echo htmlspecialchars($sidebarUserData['profilkep']); ?>" alt="<?php echo htmlspecialchars($sidebarUserData['becenev'] ?? 'Profil'); ?>">
            <?php else: ?>
            <span class="auction-nav-avatar auction-nav-avatar--fallback <?php echo $sidebarFeatured ? 'is-featured-user' : ''; ?>"><?php echo strtoupper(substr($sidebarUserData['becenev'] ?? 'P', 0, 1)); ?></span>
            <?php endif; ?>
            <span><?php echo htmlspecialchars(st('sidebar.nav.profile', 'Profilom', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
        </a>
        <a class="auction-navlink" href="kijelentkezes.php">
            <img class="auction-navlink__icon auction-navlink__icon--image" src="/kepek/icons/logout.webp" alt="">
            <span><?php echo htmlspecialchars(st('sidebar.nav.logout', 'Kilepes', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
        </a>
        <?php else: ?>
        <a class="auction-navlink" href="bejelentezes.php">
            <img class="auction-navlink__icon auction-navlink__icon--image" src="/kepek/icons/login.webp" alt="">
            <span><?php echo htmlspecialchars(st('sidebar.nav.login', 'Bejelentkezes', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
        </a>
        <a class="auction-navlink" href="regisztracio.php">
            <span class="auction-navlink__icon">&#9998;</span>
            <span><?php echo htmlspecialchars(st('sidebar.nav.register', 'Regisztracio', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
        </a>
        <?php endif; ?>
    </nav>

    <div class="auction-sidebar-footer">
        <div class="auction-sidebar-footer__links">
            <button type="button" class="auction-sidebar-footer__link" onclick="openSidebarInfoModal('hasznalati')"><?php echo htmlspecialchars(st('sidebar.footer.manual', 'Hasznalati utmutato', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></button>
        </div>
        <div class="auction-sidebar-footer__links">
            <button type="button" class="auction-sidebar-footer__link" onclick="openSidebarInfoModal('gyik')"><?php echo htmlspecialchars(st('sidebar.footer.faq', 'GYIK', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></button>
            <span>|</span>
            <button type="button" class="auction-sidebar-footer__link" onclick="openSidebarInfoModal('kapcsolat')"><?php echo htmlspecialchars(st('sidebar.footer.contact', 'Kapcsolat', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></button>
        </div>
        <div class="auction-sidebar-footer__links">
            <a class="auction-sidebar-footer__link" href="/dokumentumok/aszf.pdf" download><?php echo htmlspecialchars(st('sidebar.footer.terms', 'ASZF', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></a>
            <span>|</span>
            <a class="auction-sidebar-footer__link" href="/dokumentumok/adatvedelmi_nyilatkozat.pdf" download><?php echo htmlspecialchars(st('sidebar.footer.privacy', 'Adatvedelmi nyilatkozat', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></a>
        </div>
        <div class="auction-sidebar-footer__copy">2026 &copy; SHOBID.com</div>
		
		<div class="auction-sidebar-footer__links auction-sidebar-footer__links--country">
            <button type="button" class="auction-sidebar-footer__link auction-sidebar-footer__country-trigger" onclick="toggleSidebarCountryPicker(event)">
                <span class="auction-sidebar-footer__globe" aria-hidden="true">&#127757;</span>
                <span><?php echo htmlspecialchars((string)($sidebarCurrentMarket['name'] ?? 'Hungary')); ?></span>
            </button>
            <div class="auction-country-picker" id="auction-country-picker" hidden>
                <div class="auction-country-picker__title"><?php echo htmlspecialchars(st('sidebar.footer.country_switch', 'Orszagvaltas', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="auction-country-picker__list">
                    <?php foreach ($sidebarMarkets as $sidebarMarket): ?>
                    <?php $sidebarMarketCode = (string)($sidebarMarket['code'] ?? ''); ?>
                    <?php $sidebarMarketName = (string)($sidebarMarket['name'] ?? $sidebarMarketCode); ?>
                    <?php $sidebarMarketAvailable = !empty($sidebarMarket['available']); ?>
                    <?php $sidebarMarketActive = strtolower($sidebarMarketCode) === strtolower($sidebarCurrentMarketCode); ?>
                    <?php if ($sidebarMarketAvailable): ?>
                    <a class="auction-country-picker__item <?php echo $sidebarMarketActive ? 'is-active' : ''; ?>" href="/<?php echo htmlspecialchars($sidebarMarketCode); ?>/index.php">
                        <span class="auction-country-picker__label"><?php echo htmlspecialchars($sidebarMarketName); ?></span>
                        <span class="auction-country-picker__code"><?php echo htmlspecialchars((string)($sidebarMarket['label'] ?? strtoupper($sidebarMarketCode))); ?></span>
                    </a>
                    <?php else: ?>
                    <span class="auction-country-picker__item is-disabled <?php echo $sidebarMarketActive ? 'is-active' : ''; ?>">
                        <span class="auction-country-picker__label"><?php echo htmlspecialchars($sidebarMarketName); ?></span>
                        <span class="auction-country-picker__code"><?php echo htmlspecialchars((string)($sidebarMarket['label'] ?? strtoupper($sidebarMarketCode))); ?></span>
                    </span>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
		
    </div>

    <div class="auction-mobile-menu-drawer" id="auction-mobile-menu-drawer" hidden>
        <button type="button" class="auction-mobile-menu-drawer__close" aria-label="<?php echo htmlspecialchars(st('sidebar.mobile.close', 'Bezaras', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?>" onclick="closeSidebarMobileMenu()">&times;</button>

        <div class="auction-mobile-menu-drawer__primary">
            <?php if (isset($_SESSION['user_id'])): ?>
            <a class="auction-mobile-menu-link auction-mobile-menu-link--highlight" href="uj_aukcio.php">
                <img class="auction-mobile-menu-link__icon pupic" src="/kepek/icons/add.webp" alt="">
                <span><?php echo htmlspecialchars(st('sidebar.nav.new_offer', 'Uj ajanlat', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
            <a class="auction-mobile-menu-link auction-mobile-menu-link--profile" href="profil.php">
                <?php if (!empty($sidebarUserData['profilkep'])): ?>
                <img class="auction-mobile-menu-link__avatar <?php echo $sidebarFeatured ? 'is-featured-user' : ''; ?>" src="/profilkepek/<?php echo htmlspecialchars($sidebarUserData['profilkep']); ?>" alt="<?php echo htmlspecialchars($sidebarUserData['becenev'] ?? 'Profil'); ?>">
                <?php else: ?>
                <span class="auction-mobile-menu-link__avatar auction-mobile-menu-link__avatar--fallback <?php echo $sidebarFeatured ? 'is-featured-user' : ''; ?>"><?php echo strtoupper(substr($sidebarUserData['becenev'] ?? 'P', 0, 1)); ?></span>
                <?php endif; ?>
                <span><?php echo htmlspecialchars(st('sidebar.nav.profile', 'Profilom', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
            <?php else: ?>
            <a class="auction-mobile-menu-link" href="bejelentezes.php">
                <img class="auction-mobile-menu-link__icon pupic" src="/kepek/icons/login.webp" alt="">
                <span><?php echo htmlspecialchars(st('sidebar.nav.login', 'Bejelentkezes', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
            <a class="auction-mobile-menu-link" href="regisztracio.php">
                <!--<span class="auction-mobile-menu-link__icon pupic">&#9998;</span>-->
				<img class="auction-mobile-menu-link__icon pupic" src="/kepek/icons/login.webp" alt="">
                <span><?php echo htmlspecialchars(st('sidebar.nav.register', 'Regisztracio', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
            <?php endif; ?>
        </div>

        <div class="auction-mobile-menu-drawer__secondary">
            <button type="button" class="auction-mobile-menu-drawer__textbtn" onclick="closeSidebarMobileMenu(); openSidebarInfoModal('hasznalati');"><?php echo htmlspecialchars(st('sidebar.footer.manual', 'Hasznalati utmutato', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></button>
            <button type="button" class="auction-mobile-menu-drawer__textbtn" onclick="closeSidebarMobileMenu(); openSidebarInfoModal('gyik');"><?php echo htmlspecialchars(st('sidebar.footer.faq', 'GYIK', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></button>
            <button type="button" class="auction-mobile-menu-drawer__textbtn" onclick="closeSidebarMobileMenu(); openSidebarInfoModal('kapcsolat');"><?php echo htmlspecialchars(st('sidebar.footer.contact', 'Kapcsolat', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></button>
        </div>

        <?php if (isset($_SESSION['user_id'])): ?>
        <div class="auction-mobile-menu-drawer__logout">
            <a class="auction-mobile-menu-link auction-mobile-menu-link--logout" href="kijelentkezes.php">
                <!--<span class="auction-mobile-menu-link__lock">&#128274;</span>-->
				<img class="auction-mobile-menu-link__icon pupic" src="/kepek/icons/logout.webp" alt="">
                <span><?php echo htmlspecialchars(st('sidebar.nav.logout', 'Kilepes', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
            </a>
        </div>
        <?php endif; ?>

        <div class="auction-mobile-menu-drawer__legal">
            <div class="auction-mobile-menu-drawer__legal-links">
                <a href="/dokumentumok/aszf.pdf" download><?php echo htmlspecialchars(st('sidebar.footer.terms', 'ASZF', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></a>
                <span>|</span>
                <a href="/dokumentumok/adatvedelmi_nyilatkozat.pdf" download><?php echo htmlspecialchars(st('sidebar.footer.privacy', 'Adatvedelmi nyilatkozat', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></a>
            </div>
            <div class="auction-mobile-menu-drawer__copy">2026 &copy; SHOBID.com</div>

            <div class="auction-mobile-menu-drawer__country">
                <button type="button" class="auction-mobile-menu-drawer__country-trigger" onclick="toggleSidebarCountryPicker(event, 'auction-country-picker-mobile')">
                    <span class="auction-sidebar-footer__globe" aria-hidden="true">&#127757;</span>
                    <span><?php echo htmlspecialchars((string)($sidebarCurrentMarket['name'] ?? 'Hungary')); ?></span>
                </button>
                <div class="auction-country-picker auction-country-picker--mobile" id="auction-country-picker-mobile" hidden>
                    <div class="auction-country-picker__title"><?php echo htmlspecialchars(st('sidebar.footer.country_switch', 'Orszagvaltas', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></div>
                    <div class="auction-country-picker__list">
                        <?php foreach ($sidebarMarkets as $sidebarMarket): ?>
                        <?php $sidebarMarketCode = (string)($sidebarMarket['code'] ?? ''); ?>
                        <?php $sidebarMarketName = (string)($sidebarMarket['name'] ?? $sidebarMarketCode); ?>
                        <?php $sidebarMarketAvailable = !empty($sidebarMarket['available']); ?>
                        <?php $sidebarMarketActive = strtolower($sidebarMarketCode) === strtolower($sidebarCurrentMarketCode); ?>
                        <?php if ($sidebarMarketAvailable): ?>
                        <a class="auction-country-picker__item <?php echo $sidebarMarketActive ? 'is-active' : ''; ?>" href="/<?php echo htmlspecialchars($sidebarMarketCode); ?>/index.php">
                            <span class="auction-country-picker__label"><?php echo htmlspecialchars($sidebarMarketName); ?></span>
                            <span class="auction-country-picker__code"><?php echo htmlspecialchars((string)($sidebarMarket['label'] ?? strtoupper($sidebarMarketCode))); ?></span>
                        </a>
                        <?php else: ?>
                        <span class="auction-country-picker__item is-disabled <?php echo $sidebarMarketActive ? 'is-active' : ''; ?>">
                            <span class="auction-country-picker__label"><?php echo htmlspecialchars($sidebarMarketName); ?></span>
                            <span class="auction-country-picker__code"><?php echo htmlspecialchars((string)($sidebarMarket['label'] ?? strtoupper($sidebarMarketCode))); ?></span>
                        </span>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</aside>

<nav class="auction-mobile-nav" aria-label="Mobil navigacio">
    <a class="auction-mobile-nav__item <?php echo $sidebarMostActive ? 'is-active' : ''; ?>" href="<?php echo htmlspecialchars($sidebarIndexBase); ?>">
        <img class="auction-mobile-nav__icon" src="/kepek/icons/fire.webp" alt="">
        <span><?php echo htmlspecialchars(st('sidebar.nav.now', 'Most', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
    </a>
    <a class="auction-mobile-nav__item auction-mobile-nav__item--live <?php echo $sidebarEloActive ? 'is-active' : ''; ?>" href="<?php echo htmlspecialchars($sidebarIndexBase . '?kat=elo'); ?>">
        <img class="auction-mobile-nav__icon" src="/kepek/icons/live.webp" alt="">
        <span><?php echo htmlspecialchars(st('sidebar.nav.live', 'Elo', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
    </a>
    <a class="auction-mobile-nav__item <?php echo $sidebarKozelgoActive ? 'is-active' : ''; ?>" href="<?php echo htmlspecialchars($sidebarIndexBase . '?kat=kozelgo'); ?>">
        <img class="auction-mobile-nav__icon" src="/kepek/icons/time.webp" alt="">
        <span><?php echo htmlspecialchars(st('sidebar.nav.upcoming', 'Kozelgo', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
    </a>
    <a class="auction-mobile-nav__item <?php echo $sidebarKuponActive ? 'is-active' : ''; ?>" href="<?php echo htmlspecialchars($sidebarIndexBase . '?kat=kupon'); ?>">
        <img class="auction-mobile-nav__icon" src="/kepek/icons/kupon.webp" alt="">
        <span><?php echo htmlspecialchars(st('sidebar.nav.coupons', 'Kuponok', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
    </a>
    <a class="auction-mobile-nav__item <?php echo $sidebarPage === 'profile' ? 'is-active' : ''; ?>" href="<?php echo isset($_SESSION['user_id']) ? 'profil.php' : 'bejelentezes.php'; ?>">
        <?php if (isset($_SESSION['user_id']) && !empty($sidebarUserData['profilkep'])): ?>
        <img class="auction-mobile-nav__avatar <?php echo $sidebarFeatured ? 'is-featured-user' : ''; ?>" src="/profilkepek/<?php echo htmlspecialchars($sidebarUserData['profilkep']); ?>" alt="<?php echo htmlspecialchars($sidebarUserData['becenev'] ?? 'Profil'); ?>">
        <?php else: ?>
        <span class="auction-mobile-nav__avatar auction-mobile-nav__avatar--fallback <?php echo $sidebarFeatured ? 'is-featured-user' : ''; ?>"><?php echo isset($_SESSION['user_id']) ? strtoupper(substr($sidebarUserData['becenev'] ?? 'P', 0, 1)) : '?'; ?></span>
        <?php endif; ?>
        <span><?php echo isset($_SESSION['user_id']) ? htmlspecialchars(st('sidebar.nav.profile', 'Profilom', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8') : htmlspecialchars(st('sidebar.nav.login_short', 'Belepes', $sidebarLocaleCode), ENT_QUOTES, 'UTF-8'); ?></span>
    </a>
</nav>

<script>
function toggleSidebarCountryPicker(event, pickerId) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    var targetId = pickerId || 'auction-country-picker';
    var picker = document.getElementById(targetId);
    if (!picker) return;
    document.querySelectorAll('.auction-country-picker').forEach(function (node) {
        if (node !== picker) {
            node.hidden = true;
        }
    });
    picker.hidden = !picker.hidden;
}

function closeSidebarCountryPicker() {
    document.querySelectorAll('.auction-country-picker').forEach(function (picker) {
        picker.hidden = true;
    });
}

function toggleSidebarMobileMenu(event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    var drawer = document.getElementById('auction-mobile-menu-drawer');
    if (!drawer) return;
    var willOpen = drawer.hidden;
    drawer.hidden = !willOpen;
    document.body.classList.toggle('auction-mobile-menu-open', willOpen);
    if (!willOpen) {
        closeSidebarCountryPicker();
    }
}

function closeSidebarMobileMenu() {
    var drawer = document.getElementById('auction-mobile-menu-drawer');
    if (!drawer) return;
    drawer.hidden = true;
    document.body.classList.remove('auction-mobile-menu-open');
    closeSidebarCountryPicker();
}

document.addEventListener('click', function (event) {
    var target = event.target;
    if (target && target.nodeType === 3) {
        target = target.parentElement;
    }

    var countryLink = target && target.closest ? target.closest('.auction-country-picker__item[href]') : null;
    if (countryLink) {
        event.preventDefault();
        event.stopPropagation();
        window.location.href = countryLink.getAttribute('href');
        return;
    }

    var anyOpenPicker = false;
    var pickerElements = document.querySelectorAll('.auction-country-picker');
    pickerElements.forEach(function (pickerElement) {
        if (!pickerElement.hidden) {
            anyOpenPicker = true;
        }
    });
    if (!anyOpenPicker) return;

    var insidePickerOrTrigger = false;
    pickerElements.forEach(function (pickerElement) {
        if (insidePickerOrTrigger) return;
        var container = pickerElement.closest('.auction-sidebar-footer__links--country, .auction-mobile-menu-drawer__country');
        var trigger = container ? container.querySelector('.auction-sidebar-footer__country-trigger, .auction-mobile-menu-drawer__country-trigger') : null;
        if (target && (pickerElement.contains(target) || (trigger && trigger.contains(target)))) {
            insidePickerOrTrigger = true;
        }
    });
    if (insidePickerOrTrigger) return;

    closeSidebarCountryPicker();
});

document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
        closeSidebarMobileMenu();
    }
});
</script>
