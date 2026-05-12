<?php
return [
    'DB_HOST' => 'localhost',
    'DB_PORT' => '3306',
    'DB_USER' => 'licit_db',
    'DB_PASS' => 'Kamikaze2',
    'DB_NAME' => 'licit_db',

    'STRIPE_PUBLISHABLE_KEY' => 'pk_test_51Sz1QKQXeyH8Cw3zjlgM3yKlB13lwdJMHCzByghSpW98IMlMqUvasmBMiBrQ753UPD2aLqDNcEm8Jg7cT5stM6c100NdQdThJ8',
    'STRIPE_SECRET_KEY' => 'sk_test_51Sz1QKQXeyH8Cw3zxEmjXseV4O7Sg84wd0lU4ewsg0pQjlzALWUPIQxcsvFu8fCvd1g7urmtnspbvHzNcQa2xpNu00kRjI0Sdh',
    'STRIPE_WEBHOOK_SECRET' => 'whsec_hVHjJOR6I9dtF58cR5W2TaWtvQggqsrK',

    // Replace with a password_hash() value as soon as possible.
    'ADMIN_PASSWORD_HASH' => '$2y$12$G7dmrkqMT/pkouE32pg6QuWEkMFKsa/w7J9IeKhq.RF/JO3RLSXKW',
    'ADMIN_PASSWORD' => '',

    // Set if you want to run migrations from browser.
    'MIGRATION_TOKEN' => '',
    'PAYOUT_DAILY_HOUR' => 18,
    'PAYOUT_DAILY_MINUTE' => 33,
    'PAYOUT_DAILY_FORCE' => 0,
];

