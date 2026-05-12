<?php
return [
    // Database
    'DB_HOST' => 'localhost',
    'DB_PORT' => '3306',
    'DB_USER' => 'your_db_user',
    'DB_PASS' => 'your_db_password',
    'DB_NAME' => 'your_db_name',

    // Stripe
    'STRIPE_PUBLISHABLE_KEY' => '',
    'STRIPE_SECRET_KEY' => '',
    'STRIPE_WEBHOOK_SECRET' => '',

    // Admin login (prefer hash; plain password is fallback only)
    'ADMIN_PASSWORD_HASH' => '',
    'ADMIN_PASSWORD' => '',

    // Migration endpoint token (optional, only needed for web-triggered migration)
    'MIGRATION_TOKEN' => '',

];
