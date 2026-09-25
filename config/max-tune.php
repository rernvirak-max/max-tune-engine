<?php

return [

    /*
    |--------------------------------------------------------------------------
    | App mode
    |--------------------------------------------------------------------------
    |
    | personal — single-user / register disabled
    | invite   — invite codes required to register
    | public   — open registration
    |
    */

    'mode' => env('APP_MODE', 'personal'),

    /*
    |--------------------------------------------------------------------------
    | Media disk
    |--------------------------------------------------------------------------
    */

    'media_disk' => env('MEDIA_DISK', 'media'),

    'allowed_audio_mimes' => [
        'audio/mpeg',
        'audio/mp4',
        'audio/x-m4a',
        'audio/flac',
        'audio/wav',
        'audio/x-wav',
        'audio/wave',
    ],

    'allowed_audio_extensions' => ['mp3', 'm4a', 'flac', 'wav'],

    /** Max upload size in kilobytes (Laravel File::max) — default 50 MB */
    'max_upload_kb' => (int) env('MAX_UPLOAD_KB', 51200),

    /** Default per-user storage quota in bytes — 5 GB */
    'default_storage_quota_bytes' => (int) env('DEFAULT_STORAGE_QUOTA_BYTES', 5 * 1024 * 1024 * 1024),

    /** Upload rate limit per user */
    'upload_rate_limit' => (int) env('UPLOAD_RATE_LIMIT', 20),
    'upload_rate_decay_seconds' => (int) env('UPLOAD_RATE_DECAY_SECONDS', 3600),

    'jamendo' => [
        'client_id' => env('JAMENDO_CLIENT_ID'),
    ],

];
