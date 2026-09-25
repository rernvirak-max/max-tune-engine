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

    /*
    |--------------------------------------------------------------------------
    | Add from YouTube link
    |--------------------------------------------------------------------------
    |
    | Imports run on the "imports" queue via yt-dlp (+ ffmpeg for the m4a
    | remux and jpg thumbnail). Per-file size reuses max_upload_kb and the
    | storage quota above.
    |
    */

    'youtube' => [
        'ytdlp_binary' => env('YTDLP_BINARY', 'yt-dlp'),
        /** Directory or binary passed to yt-dlp --ffmpeg-location; empty = ffmpeg on PATH */
        'ffmpeg_binary' => env('FFMPEG_BINARY'),
        /** Optional yt-dlp --js-runtimes value (e.g. "node"); empty = yt-dlp default (deno) */
        'js_runtimes' => env('YTDLP_JS_RUNTIMES'),
        'queue' => env('YOUTUBE_IMPORT_QUEUE', 'imports'),
        'max_duration_seconds' => (int) env('YOUTUBE_MAX_DURATION_SECONDS', 15 * 60),
        /** Whole job budget (metadata + download); worker --timeout must be higher */
        'job_timeout_seconds' => (int) env('YOUTUBE_JOB_TIMEOUT_SECONDS', 10 * 60),
        'version_timeout_seconds' => 10,
        /** Extra time after the job timeout before the sweep marks an import interrupted */
        'stuck_grace_seconds' => (int) env('YOUTUBE_STUCK_GRACE_SECONDS', 5 * 60),
        'rate_limit' => (int) env('YOUTUBE_IMPORT_RATE_LIMIT', 10),
        'rate_decay_seconds' => (int) env('YOUTUBE_IMPORT_RATE_DECAY_SECONDS', 3600),
        'max_active_per_user' => (int) env('YOUTUBE_MAX_ACTIVE_IMPORTS', 2),
        /** Backoff before each automatic retry when YouTube blocks the server */
        'blocked_retry_delays_seconds' => [60, 5 * 60],
        'list_limit' => 100,
        /** Encrypted Netscape cookies.txt on the private "local" disk (never served) */
        'cookies_path' => 'youtube/cookies.txt.enc',
        'cookies_max_kb' => 1024,
        /** Scratch space for in-flight downloads; wiped per import and by the sweep */
        'work_dir' => storage_path('app/tmp/imports'),
    ],

];
