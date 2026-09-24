<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'CORS_ALLOWED_ORIGINS',
            'http://localhost:9100,http://127.0.0.1:9100,http://localhost:9101,http://127.0.0.1:9101'
        ))
    ))),

    // Any localhost / 127.0.0.1 port (Quasar often bumps 9100 → 9101)
    'allowed_origins_patterns' => [
        '#^http://localhost:\d+$#',
        '#^http://127\.0\.0\.1:\d+$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Bearer token API — credentials not required
    'supports_credentials' => false,

];
