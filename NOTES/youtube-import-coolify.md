# YouTube import (Coolify)

MaxTune can download YouTube audio into the library via `yt-dlp`.

## Server packages

Inside the **maxtune-engine** container (or image build), install:

- `yt-dlp`
- `ffmpeg`

Example (Debian/Ubuntu image):

```bash
apt-get update && apt-get install -y ffmpeg curl ca-certificates
curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp -o /usr/local/bin/yt-dlp
chmod a+rx /usr/local/bin/yt-dlp
```

Optional env:

```env
YTDLP_BINARY=yt-dlp
YOUTUBE_DOWNLOAD_TIMEOUT=600
QUEUE_CONNECTION=database
```

## Queue worker (required)

Imports are queued. Without a worker they stay on **Queued / Waiting for metadata**.

In Coolify, add a process / command for the same app:

```bash
php artisan queue:work --sleep=1 --tries=2 --timeout=600
```

Also run migrations after deploy:

```bash
php artisan migrate --force
```

## Storage

Audio is stored on the media disk (`/app/storage/app/media`), which should be the HDD bind mount `/mnt/data/maxtune/storage`.
