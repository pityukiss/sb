<?php
include_once __DIR__ . '/config.php';

if (!defined('SHOBID_STRIPE_PUBLISHABLE_KEY')) {
    define('SHOBID_STRIPE_PUBLISHABLE_KEY', trim((string) shobidConfig('STRIPE_PUBLISHABLE_KEY', '')));
}
if (!defined('SHOBID_STRIPE_SECRET_KEY')) {
    define('SHOBID_STRIPE_SECRET_KEY', trim((string) shobidConfig('STRIPE_SECRET_KEY', '')));
}
if (!defined('SHOBID_STRIPE_WEBHOOK_SECRET')) {
    define('SHOBID_STRIPE_WEBHOOK_SECRET', trim((string) shobidConfig('STRIPE_WEBHOOK_SECRET', '')));
}
