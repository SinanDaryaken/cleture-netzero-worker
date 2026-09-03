<?php

return [
    'connection' => env('IDENTITY_DB_CONNECTION', env('DB_CONNECTION', 'central')),
    'netzero_my_url' => env('NETZERO_MY_URL', 'https://cleture-netzero-my.test'),

    'mail' => [
        'fallback_locale' => 'tr',
    ],

    'paths' => [
        'email_verification' => env('IDENTITY_EMAIL_VERIFICATION_PATH', '/email/verify'),
        'password_reset' => env('IDENTITY_PASSWORD_RESET_PATH', '/reset-password'),
    ],

    'email_verification' => [
        'table' => env(
            'IDENTITY_EMAIL_VERIFICATION_TOKEN_TABLE',
            'organization_user_email_verification_tokens',
        ),
        'expire' => (int) env('IDENTITY_EMAIL_VERIFICATION_EXPIRE', 60),
    ],

    'password_reset' => [
        'broker' => env('IDENTITY_PASSWORD_BROKER', 'organization_users'),
    ],
];
