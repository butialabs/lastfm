# LastFM

[![Docker](https://img.shields.io/badge/Docker-Ready-blue?logo=docker)](https://ghcr.io/butialabs/lastfm)
[![PHP](https://img.shields.io/badge/PHP-8.4+-purple?logo=php)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-13-red?logo=laravel)](https://laravel.com)

**LastFM** automates weekly posting of your Last.fm *Weekly Artist Chart* to **Bluesky (AT Protocol)** and **Mastodon**.

🌐 **Public Instance:** [https://lastfm.butialabs.com](https://lastfm.butialabs.com)

---

## 🐳 Docker Installation (Recommended)

### Quick Start

1. Create a `compose.yml`:

```yaml
services:
  lastfm:
    image: ghcr.io/butialabs/lastfm:latest
    container_name: lastfm
    environment:
      TZ: UTC
      PHP_DATE_TIMEZONE: UTC
      APP_URL: https://your-domain.com
      APP_KEY: base64:your_app_key # optional, auto-generated on first boot
      LASTFM_API: your_lastfm_api_key
      ADMIN_USER: admin
      ADMIN_PASSWORD: your_secure_password
    ports:
      - 80:8080
    volumes:
      - lastfm-data:/app/data
    restart: unless-stopped

volumes:
  lastfm-data:
```

2. Start the container:

```bash
docker compose up -d
```

3. Access the application at `http://localhost` (or your configured domain). The admin panel is at `/admin`.

### Environment Variables

| Variable | Description | Required |
|----------|-------------|----------|
| `APP_URL` | Public URL of your instance | Yes |
| `LASTFM_API` | Your Last.fm API key ([Get one here](https://www.last.fm/api/account/create)) | Yes |
| `ADMIN_USER` | Initial admin username (seeded on first boot) | First boot |
| `ADMIN_PASSWORD` | Initial admin password (seeded on first boot, stored hashed) | First boot |
| `APP_KEY` | Laravel encryption key (`php artisan key:generate --show`). Auto-generated and persisted in the data volume if omitted | No |
| `TZ` / `PHP_DATE_TIMEZONE` | Container and PHP timezone (e.g., `America/Sao_Paulo`). Schedules are always stored and compared in UTC | No |
| `LASTFM_PROXY_URL` | Proxy fallback for Last.fm image scraping (see below) | No |
| `THEAUDIODB_API_KEY` / `FANART_API_KEY` | Alternative artist image providers for the admin panel | No |
| `MAX_ERROR_COUNT` | Send attempts before giving up until next week (default `3`) | No |
| `BLUESKY_MENTION` / `MASTODON_MENTION` | Account mentioned in generated posts (defaults to the Butiá Labs accounts) | No |
| `IMAGE_PLACEHOLDER_RETRY_DAYS` | Days before a placeholder (failed or stub download) is attempted again, when the artist next shows up in a chart (default `30`) | No |
| `IMAGE_STORAGE` | Where montages and cached artist/album images are stored: `local` (default, the data volume) or `s3` | No |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` / `AWS_BUCKET` / `AWS_DEFAULT_REGION` | S3 credentials and bucket, used when `IMAGE_STORAGE=s3` | With S3 |
| `AWS_ENDPOINT` / `AWS_USE_PATH_STYLE_ENDPOINT` | Custom endpoint for S3-compatible services (Cloudflare R2, MinIO, ...). MinIO usually needs path-style `true` | No |
| `AWS_ROOT` | Optional key prefix inside the bucket (objects go to `{AWS_ROOT}/montage`, `/artists`, `/albums`) | No |
| `IMAGE_SIZE` / `IMAGE_QUALITY` / `IMAGE_FORMAT` | Normalization of downloaded images: square crop of at most `IMAGE_SIZE` px (default `600`), quality (default `80`), `webp` (default) or `jpeg` | No |

The base image's own variables (`PHP_OPCACHE_*`, `NGINX_*`, `SSL_MODE`, `AUTORUN_*`, ...) are documented in the [serversideup/php reference](https://serversideup.net/open-source/docker-php/docs/reference/environment-variable-specification).

> **APP_KEY stability:** encrypted credentials (Bluesky app passwords / Mastodon tokens) are tied to `APP_KEY`. Once set, by you or auto-generated into `data/.app_key`, never change it, or users will need to log in again.

### Upgrading from v1 (custom PHP app)

The automatic v1 import was removed. Upgrade to **2.0.9** first (keeping `ENCRYPTION_KEY` set on its first boot so the old database is imported), then to the latest version. `ENCRYPTION_KEY` is no longer used and can be removed.

### Proxy fallback

In 2019, Last.fm removed the image API, so artist images have to be scraped from the public artist page. Under heavy traffic Last.fm will block requests (`403`/`429`). Image and page requests use a two-stage fallback:

**Proxy (2 attempts) → Direct (1 attempt).**

- If `LASTFM_PROXY_URL` is set, the request is tried twice through that proxy first.
- The direct attempt runs last, rotating User-Agents and sending full browser headers to reduce bot-detection hits.
- If `LASTFM_PROXY_URL` is empty, only the direct attempt runs.

```env
LASTFM_PROXY_URL=http://user:pass@host:port
```

### Image storage (S3)

Every downloaded image (artist photos and album covers) is center-cropped to a square, resized to at most 600px and re-encoded as WebP without metadata, which keeps the cache small. Montages are still JPEG (kept under 1MB for Bluesky).

By default everything lives in the data volume. To use S3 or an S3-compatible service:

```yaml
    environment:
      IMAGE_STORAGE: s3
      AWS_ACCESS_KEY_ID: ...
      AWS_SECRET_ACCESS_KEY: ...
      AWS_DEFAULT_REGION: us-east-1
      AWS_BUCKET: lastfm-images
      # AWS_ENDPOINT: https://<account>.r2.cloudflarestorage.com
      # AWS_USE_PATH_STYLE_ENDPOINT: "true"   # MinIO
```

The bucket can stay private: images are served through the app (`/montage/{hash}`) and read server side.

To move an existing installation, run once after switching:

```bash
php artisan lastfm:files s3                    # copy data/ images (montages + caches) to the bucket
php artisan lastfm:files s3 --delete-source    # same, removing the local copies
php artisan lastfm:files normalize-cache       # convert the existing cache to WebP (local or S3)
```

`normalize-cache` works on the configured storage (the bucket when `IMAGE_STORAGE=s3`), so run it after `s3`. It also works on local-only installs. Without it, old `.jpg` cache entries are converted on first use.

### Persistent Data

Mount the `/app/data` volume to persist:
- SQLite database (`db/database.db`) and the generated `.app_key`
- Artist and album image caches (`cache/artists/`, `cache/albums/`), unless `IMAGE_STORAGE=s3`
- Generated montages (`montage/`), unless `IMAGE_STORAGE=s3`
- Application logs (`logs/`)

---

## 👨‍💻 Developer Installation

### Requirements

- PHP >= 8.4 with `gd`, `intl`, `pdo_sqlite`
- Composer

### Setup

```bash
cd lastfm/app
composer setup    # install, .env, key, migrate, seed admin
composer dev      # php artisan serve
```

Edit `.env` with your settings (`LASTFM_API`, `ADMIN_USER`, `ADMIN_PASSWORD`, ...).

Code style (Pint) and the test suite (Pest):

```bash
composer lint
composer test
```

---

## 🔧 CLI Commands

```bash
# Process scheduled users (generate montages, mark as QUEUED)
php artisan lastfm:schedule

# Process the queue (send posts to Bluesky/Mastodon)
php artisan lastfm:send

# Force process + send for a single user
php artisan lastfm:force-send {user_id}

# Copy local images to S3 (IMAGE_STORAGE=s3)
php artisan lastfm:files s3 [--delete-source]

# Convert the image cache to the standard format (WebP, square, resized)
php artisan lastfm:files normalize-cache
```

Outside Docker, run the scheduler yourself:

```bash
php artisan schedule:work
```

---

**Made with ❤️ by [Butiá Labs](https://butialabs.com)**
