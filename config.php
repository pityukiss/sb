<?php
if (!isset($GLOBALS['shobidLocalConfig'])) {
    $GLOBALS['shobidLocalConfig'] = [];
    $localConfigPath = __DIR__ . '/config.local.php';
    if (is_file($localConfigPath)) {
        $loaded = include $localConfigPath;
        if (is_array($loaded)) {
            $GLOBALS['shobidLocalConfig'] = $loaded;
        }
    }
}

if (!function_exists('shobidConfig')) {
    function shobidConfig($key, $default = '')
    {
        $key = (string)$key;
        $local = $GLOBALS['shobidLocalConfig'] ?? [];
        if (is_array($local) && array_key_exists($key, $local)) {
            return $local[$key];
        }

        $envValue = getenv($key);
        if ($envValue !== false) {
            return $envValue;
        }

        if (isset($_ENV[$key])) {
            return $_ENV[$key];
        }
        if (isset($_SERVER[$key])) {
            return $_SERVER[$key];
        }
        return $default;
    }
}
