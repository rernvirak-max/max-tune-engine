# MaxTune Engine

Laravel API for **MaxTune** — auth, library, streaming, playlists.

## Stack

- Laravel 13 + Sanctum
- SQLite by default (MySQL ready via `.env`)
- Local `media` disk (swap to S3/R2 later via `MEDIA_DISK`)

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
# Prefer Herd: https://max-tune-engine.test`n# Or: php artisan serve`n```

API: https://max-tune-engine.test

## Phase 0 seed user

| Field | Value |
|-------|-------|
| Email | `vireak@maxtune.local` |
| Password | `password` |
| Role | `admin` |

`APP_MODE=personal` — registration disabled.

## Key endpoints

| Method | Path | Auth |
|--------|------|------|
| POST | `/api/auth/login` | — |
| POST | `/api/auth/logout` | Bearer |
| POST | `/api/auth/register` | — (blocked in personal) |
| GET | `/api/me` | Bearer |

## Config

| Env | Default | Notes |
|-----|---------|-------|
| `APP_MODE` | `personal` | `personal` \| `invite` \| `public` |
| `MEDIA_DISK` | `media` | Storage abstraction |
| `CORS_ALLOWED_ORIGINS` | Quasar `:9100` | Comma-separated |

## Deploy (Coolify, Nixpacks)

Three services from this repo, same env and the same persistent volume at `/app/storage/app` (see `Procfile`):

| Service | Command |
|---------|---------|
| web | `php artisan serve --host=0.0.0.0 --port=8000 --no-reload` |
| worker (exactly 1 replica, no domain) | `php artisan queue:work database-imports --queue=imports --timeout=660 --tries=1 --sleep=3` |
| scheduler | `php artisan schedule:work` (runs `imports:sweep` every 5 min: fails stuck/lost imports, deletes failed imports after `YOUTUBE_FAILED_RETENTION_DAYS`, default 7) |

The worker command must be used **exactly**: YouTube imports run on the dedicated `database-imports` queue connection, and Laravel applies the `retry_after` of the connection the worker pulls from. Only `database-imports` (`YOUTUBE_QUEUE_RETRY_AFTER`, default 900) outlives the job's 660s timeout. A worker on another connection could hand a long import out again and mark it interrupted. (The default `database` connection's `DB_QUEUE_RETRY_AFTER` defaults to 720 as a safety net, but don't rely on it.)

Env: `QUEUE_CONNECTION=database`. yt-dlp, deno and ffmpeg are installed by `nixpacks.toml` (pinned, sha256-checked). To update yt-dlp, bump `YTDLP_VERSION` and `YTDLP_SHA256` together; to update deno, bump `DENO_VERSION` and `DENO_SHA256` together (a mismatch fails the build). After redeploying only the web app, run `php artisan queue:restart`.
