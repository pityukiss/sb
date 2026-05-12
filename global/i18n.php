<?php

include_once __DIR__ . '/markets.php';

if (!function_exists('shobidI18nDefaultLocale')) {
    function shobidI18nDefaultLocale() {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $default = 'hu-hu';
        $path = __DIR__ . '/i18n_settings.json';
        if (is_file($path)) {
            $decoded = json_decode((string)file_get_contents($path), true);
            if (is_array($decoded)) {
                $candidate = strtolower(trim((string)($decoded['default_locale'] ?? '')));
                if ($candidate !== '') {
                    $default = $candidate;
                }
            }
        }

        $supported = function_exists('shobidI18nSupportedLocales') ? shobidI18nSupportedLocales() : ['hu-hu'];
        if (!in_array($default, $supported, true)) {
            $default = 'hu-hu';
        }

        $cache = $default;
        return $cache;
    }
}

if (!function_exists('shobidI18nSetDefaultLocale')) {
    function shobidI18nSetDefaultLocale($locale) {
        $candidate = strtolower(trim((string)$locale));
        $supported = shobidI18nSupportedLocales();
        if (!in_array($candidate, $supported, true)) {
            return false;
        }

        $path = __DIR__ . '/i18n_settings.json';
        $payload = ['default_locale' => $candidate];
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return false;
        }
        $written = @file_put_contents($path, $json);
        if ($written === false) {
            return false;
        }

        return true;
    }
}

if (!function_exists('shobidI18nSupportedLocales')) {
    function shobidI18nSupportedLocales() {
        $codes = function_exists('shobidMarketCodes') ? shobidMarketCodes() : [];
        $out = [];
        foreach ($codes as $code) {
            $normalized = strtolower(trim((string)$code));
            if ($normalized !== '') {
                $out[] = $normalized;
            }
        }
        if (empty($out)) {
            $out[] = shobidI18nDefaultLocale();
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('shobidI18nNormalizeLocale')) {
    function shobidI18nNormalizeLocale($locale) {
        $candidate = strtolower(trim((string)$locale));
        if ($candidate === '') {
            return shobidI18nDefaultLocale();
        }
        foreach (shobidI18nSupportedLocales() as $supported) {
            if ($candidate === $supported) {
                return $supported;
            }
        }
        return shobidI18nDefaultLocale();
    }
}

if (!function_exists('shobidI18nCurrentLocale')) {
    function shobidI18nCurrentLocale() {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $fromRequest = function_exists('shobidDetectMarketCodeFromRequest')
            ? shobidDetectMarketCodeFromRequest()
            : '';
        if ($fromRequest !== '') {
            $cache = shobidI18nNormalizeLocale($fromRequest);
            return $cache;
        }

        $cookieLocale = trim((string)($_COOKIE['shobid_market'] ?? ''));
        if ($cookieLocale !== '') {
            $cache = shobidI18nNormalizeLocale($cookieLocale);
            return $cache;
        }

        $cache = shobidI18nDefaultLocale();
        return $cache;
    }
}

if (!function_exists('shobidI18nDir')) {
    function shobidI18nDir() {
        $dir = __DIR__ . '/translations';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir;
    }
}

if (!function_exists('shobidI18nFilePath')) {
    function shobidI18nFilePath($locale) {
        $normalized = shobidI18nNormalizeLocale($locale);
        return shobidI18nDir() . '/' . $normalized . '.json';
    }
}

if (!function_exists('shobidI18nLoadLocaleMessages')) {
    function shobidI18nLoadLocaleMessages($locale) {
        $normalized = shobidI18nNormalizeLocale($locale);
        if (!isset($GLOBALS['shobid_i18n_cache']) || !is_array($GLOBALS['shobid_i18n_cache'])) {
            $GLOBALS['shobid_i18n_cache'] = [];
        }
        if (isset($GLOBALS['shobid_i18n_cache'][$normalized])) {
            return $GLOBALS['shobid_i18n_cache'][$normalized];
        }

        $path = shobidI18nFilePath($normalized);
        if (!is_file($path)) {
            $GLOBALS['shobid_i18n_cache'][$normalized] = [];
            return [];
        }

        $decoded = json_decode((string)file_get_contents($path), true);
        if (!is_array($decoded)) {
            $GLOBALS['shobid_i18n_cache'][$normalized] = [];
            return [];
        }

        $messages = [];
        foreach ($decoded as $key => $value) {
            $msgKey = trim((string)$key);
            if ($msgKey === '') {
                continue;
            }
            $messages[$msgKey] = trim((string)$value);
        }

        ksort($messages);
        $GLOBALS['shobid_i18n_cache'][$normalized] = $messages;
        return $messages;
    }
}

if (!function_exists('shobidI18nSaveLocaleMessages')) {
    function shobidI18nSaveLocaleMessages($locale, $messages) {
        $normalized = shobidI18nNormalizeLocale($locale);
        $clean = [];
        if (is_array($messages)) {
            foreach ($messages as $key => $value) {
                $msgKey = trim((string)$key);
                if ($msgKey === '') {
                    continue;
                }
                $clean[$msgKey] = trim((string)$value);
            }
        }
        ksort($clean);
        $json = json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return false;
        }

        $written = @file_put_contents(shobidI18nFilePath($normalized), $json);
        if ($written === false) {
            return false;
        }
        if (!isset($GLOBALS['shobid_i18n_cache']) || !is_array($GLOBALS['shobid_i18n_cache'])) {
            $GLOBALS['shobid_i18n_cache'] = [];
        }
        $GLOBALS['shobid_i18n_cache'][$normalized] = $clean;
        return true;
    }
}

if (!function_exists('shobidI18nAllMessages')) {
    function shobidI18nAllMessages() {
        $all = [];
        foreach (shobidI18nSupportedLocales() as $locale) {
            $all[$locale] = shobidI18nLoadLocaleMessages($locale);
        }
        return $all;
    }
}

if (!function_exists('shobidI18nAllKeys')) {
    function shobidI18nAllKeys() {
        $keys = [];
        $all = shobidI18nAllMessages();
        foreach ($all as $localeMessages) {
            foreach ($localeMessages as $key => $value) {
                $keys[$key] = true;
            }
        }
        $list = array_keys($keys);
        sort($list);
        return $list;
    }
}

if (!function_exists('shobidI18nTranslate')) {
    function shobidI18nTranslate($key, $fallback = '', $locale = null) {
        $msgKey = trim((string)$key);
        if ($msgKey === '') {
            return trim((string)$fallback);
        }
        $fallbackText = trim((string)$fallback);
        if ($fallbackText !== '' && function_exists('shobidI18nEnsureDefaultKey')) {
            shobidI18nEnsureDefaultKey($msgKey, $fallbackText);
        }
        $activeLocale = $locale !== null ? shobidI18nNormalizeLocale($locale) : shobidI18nCurrentLocale();
        $messages = shobidI18nLoadLocaleMessages($activeLocale);
        if (array_key_exists($msgKey, $messages) && trim((string)$messages[$msgKey]) !== '') {
            return (string)$messages[$msgKey];
        }
        $defaultLocale = shobidI18nDefaultLocale();
        if ($activeLocale !== $defaultLocale) {
            $defaultMessages = shobidI18nLoadLocaleMessages($defaultLocale);
            if (array_key_exists($msgKey, $defaultMessages) && trim((string)$defaultMessages[$msgKey]) !== '') {
                return (string)$defaultMessages[$msgKey];
            }
        }
        return $fallbackText !== '' ? $fallbackText : $msgKey;
    }
}

if (!function_exists('shobidI18nEnsureDefaultKey')) {
    function shobidI18nEnsureDefaultKey($key, $fallback) {
        static $ensured = [];
        $msgKey = trim((string)$key);
        $fallbackText = trim((string)$fallback);
        if ($msgKey === '' || $fallbackText === '') {
            return;
        }
        if (isset($ensured[$msgKey])) {
            return;
        }

        $defaultLocale = shobidI18nDefaultLocale();
        $messages = shobidI18nLoadLocaleMessages($defaultLocale);
        if (!array_key_exists($msgKey, $messages)) {
            $messages[$msgKey] = $fallbackText;
            shobidI18nSaveLocaleMessages($defaultLocale, $messages);
        }
        $ensured[$msgKey] = true;
    }
}

if (!function_exists('st')) {
    function st($key, $fallback = '', $locale = null) {
        return shobidI18nTranslate($key, $fallback, $locale);
    }
}

if (!function_exists('shobidI18nCategoryKey')) {
    function shobidI18nCategoryKey($categoryId) {
        $id = intval($categoryId);
        if ($id < 1) {
            return '';
        }
        return 'category.' . $id . '.name';
    }
}

if (!function_exists('shobidI18nCategoryName')) {
    function shobidI18nCategoryName($categoryId, $fallback = '', $locale = null) {
        $key = shobidI18nCategoryKey($categoryId);
        $fallbackText = trim((string)$fallback);
        if ($key === '') {
            return $fallbackText;
        }

        $activeLocale = $locale !== null ? shobidI18nNormalizeLocale($locale) : shobidI18nCurrentLocale();
        $messages = shobidI18nLoadLocaleMessages($activeLocale);
        if (array_key_exists($key, $messages) && trim((string)$messages[$key]) !== '') {
            return trim((string)$messages[$key]);
        }

        $defaultLocale = shobidI18nDefaultLocale();
        if ($activeLocale !== $defaultLocale) {
            $defaultMessages = shobidI18nLoadLocaleMessages($defaultLocale);
            if (array_key_exists($key, $defaultMessages) && trim((string)$defaultMessages[$key]) !== '') {
                return trim((string)$defaultMessages[$key]);
            }
        }

        return $fallbackText !== '' ? $fallbackText : $key;
    }
}

if (!function_exists('shobidI18nSaveCategoryTranslations')) {
    function shobidI18nSaveCategoryTranslations($categoryId, $translationsByLocale, $fallbackDefault = '') {
        $key = shobidI18nCategoryKey($categoryId);
        if ($key === '') {
            return false;
        }

        $input = is_array($translationsByLocale) ? $translationsByLocale : [];
        $defaultLocale = shobidI18nDefaultLocale();
        $defaultFallback = trim((string)$fallbackDefault);
        $savedAny = false;

        foreach (shobidI18nSupportedLocales() as $locale) {
            $messages = shobidI18nLoadLocaleMessages($locale);
            $value = trim((string)($input[$locale] ?? ''));

            if ($locale === $defaultLocale && $value === '' && $defaultFallback !== '') {
                $value = $defaultFallback;
            }

            if (!array_key_exists($key, $messages) || $messages[$key] !== $value) {
                $messages[$key] = $value;
                if (shobidI18nSaveLocaleMessages($locale, $messages)) {
                    $savedAny = true;
                }
            }
        }

        return $savedAny;
    }
}

if (!function_exists('shobidI18nDeleteCategoryTranslations')) {
    function shobidI18nDeleteCategoryTranslations($categoryId) {
        $key = shobidI18nCategoryKey($categoryId);
        if ($key === '') {
            return false;
        }

        $deletedAny = false;
        foreach (shobidI18nSupportedLocales() as $locale) {
            $messages = shobidI18nLoadLocaleMessages($locale);
            if (array_key_exists($key, $messages)) {
                unset($messages[$key]);
                if (shobidI18nSaveLocaleMessages($locale, $messages)) {
                    $deletedAny = true;
                }
            }
        }

        return $deletedAny;
    }
}

if (!function_exists('shobidI18nCouponCategoryKey')) {
    function shobidI18nCouponCategoryKey($categoryId) {
        $id = intval($categoryId);
        if ($id < 1) {
            return '';
        }
        return 'coupon_category.' . $id . '.name';
    }
}

if (!function_exists('shobidI18nCouponCategoryName')) {
    function shobidI18nCouponCategoryName($categoryId, $fallback = '', $locale = null) {
        $key = shobidI18nCouponCategoryKey($categoryId);
        $fallbackText = trim((string)$fallback);
        if ($key === '') {
            return $fallbackText;
        }

        $activeLocale = $locale !== null ? shobidI18nNormalizeLocale($locale) : shobidI18nCurrentLocale();
        $messages = shobidI18nLoadLocaleMessages($activeLocale);
        if (array_key_exists($key, $messages) && trim((string)$messages[$key]) !== '') {
            return trim((string)$messages[$key]);
        }

        $defaultLocale = shobidI18nDefaultLocale();
        if ($activeLocale !== $defaultLocale) {
            $defaultMessages = shobidI18nLoadLocaleMessages($defaultLocale);
            if (array_key_exists($key, $defaultMessages) && trim((string)$defaultMessages[$key]) !== '') {
                return trim((string)$defaultMessages[$key]);
            }
        }

        return $fallbackText !== '' ? $fallbackText : $key;
    }
}

if (!function_exists('shobidI18nSaveCouponCategoryTranslations')) {
    function shobidI18nSaveCouponCategoryTranslations($categoryId, $translationsByLocale, $fallbackDefault = '') {
        $key = shobidI18nCouponCategoryKey($categoryId);
        if ($key === '') {
            return false;
        }

        $input = is_array($translationsByLocale) ? $translationsByLocale : [];
        $defaultLocale = shobidI18nDefaultLocale();
        $defaultFallback = trim((string)$fallbackDefault);
        $savedAny = false;

        foreach (shobidI18nSupportedLocales() as $locale) {
            $messages = shobidI18nLoadLocaleMessages($locale);
            $value = trim((string)($input[$locale] ?? ''));

            if ($locale === $defaultLocale && $value === '' && $defaultFallback !== '') {
                $value = $defaultFallback;
            }

            if (!array_key_exists($key, $messages) || $messages[$key] !== $value) {
                $messages[$key] = $value;
                if (shobidI18nSaveLocaleMessages($locale, $messages)) {
                    $savedAny = true;
                }
            }
        }

        return $savedAny;
    }
}

if (!function_exists('shobidI18nDeleteCouponCategoryTranslations')) {
    function shobidI18nDeleteCouponCategoryTranslations($categoryId) {
        $key = shobidI18nCouponCategoryKey($categoryId);
        if ($key === '') {
            return false;
        }

        $deletedAny = false;
        foreach (shobidI18nSupportedLocales() as $locale) {
            $messages = shobidI18nLoadLocaleMessages($locale);
            if (array_key_exists($key, $messages)) {
                unset($messages[$key]);
                if (shobidI18nSaveLocaleMessages($locale, $messages)) {
                    $deletedAny = true;
                }
            }
        }

        return $deletedAny;
    }
}

if (!function_exists('shobidI18nResolveMaybeKey')) {
    function shobidI18nResolveMaybeKey($value, $locale = null, $fallback = '') {
        $raw = trim((string)$value);
        if ($raw === '') {
            return trim((string)$fallback);
        }

        if (preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)+$/i', $raw)) {
            $translated = shobidI18nTranslate($raw, '', $locale);
            if ($translated !== '' && $translated !== $raw) {
                return $translated;
            }
            $fallbackText = trim((string)$fallback);
            return $fallbackText !== '' ? $fallbackText : '';
        }

        return $raw;
    }
}
