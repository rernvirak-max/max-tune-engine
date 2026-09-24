<?php

return [

    /*
    |--------------------------------------------------------------------------
    | App mode
    |--------------------------------------------------------------------------
    |
    | personal — single-user / register disabled
    | invite   — invite codes or admin approve
    | public   — open registration
    |
    */

    'mode' => env('APP_MODE', 'personal'),

    /*
    |--------------------------------------------------------------------------
    | Media disk
    |--------------------------------------------------------------------------
    |
    | Local first; point MEDIA_DISK / FILESYSTEM at S3-compatible later
    | without rewriting domain logic.
    |
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

    /** Max upload size in kilobytes (Laravel File::max) */
    'max_upload_kb' => (int) env('MAX_UPLOAD_KB', 102400),

    'jamendo' => [
        'client_id' => env('JAMENDO_CLIENT_ID'),
    ],

];
