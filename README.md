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
| `ENCRYPTION_KEY` | **Upgrades from v1 only**: legacy key used to decrypt old credentials during the one-time import. Can be removed afterwards | No |
| `TZ` / `PHP_DATE_TIMEZONE` | Container and PHP timezone (e.g., `America/Sao_Paulo`). Schedules are always stored and compared in UTC | No |
| `LASTFM_PROXY_URL` | Proxy fallback for Last.fm image scraping (see below) | No |
| `THEAUDIODB_API_KEY` / `FANART_API_KEY` | Alternative artist image providers for the admin panel | No |
| `MAX_ERROR_COUNT` | Send attempts before giving up until next week (default `3`) | No |
| `BLUESKY_MENTION` / `MASTODON_MENTION` | Account mentioned in generated posts (defaults to the Butiá Labs accounts) | No |
| `IMAGE_BACKFILL_PER_TICK` | Artists retried per scheduler tick (default `5`, see below) | No |
| `IMAGE_PLACEHOLDER_RETRY_DAYS` | Days before a placeholder result is attempted again (default `30`) | No |

The base image's own variables (`PHP_OPCACHE_*`, `NGINX_*`, `SSL_MODE`, `AUTORUN_*`, ...) are documented in the [serversideup/php reference](https://serversideup.net/open-source/docker-php/docs/reference/environment-variable-specification).

> **APP_KEY stability:** encrypted credentials (Bluesky app passwords / Mastodon tokens) are tied to `APP_KEY`. Once set, by you or auto-generated into `data/.app_key`, never change it, or users will need to log in again.

### Upgrading from v1 (custom PHP app)

Keep `ENCRYPTION_KEY` (the old 32-char key) set on the **first boot** of the new version.
After a successful import, `ENCRYPTION_KEY` can be removed from the environment.

### Proxy fallback

In 2019, Last.fm removed the image API, so artist images have to be scraped from the public artist page. Under heavy traffic Last.fm will block requests (`403`/`429`). Image and page requests use a two-stage fallback:

**Proxy (2 attempts) → Direct (1 attempt).**

- If `LASTFM_PROXY_URL` is set, the request is tried twice through that proxy first.
- The direct attempt runs last, rotating User-Agents and sending full browser headers to reduce bot-detection hits.
- If `LASTFM_PROXY_URL` is empty, only the direct attempt runs.

```env
LASTFM_PROXY_URL=http://user:pass@host:port
```

### Persistent Data

Mount the `/app/data` volume to persist:
- SQLite database (`db/database.db`) and the generated `.app_key`
- Artist image cache (`cache/artists/`)
- Generated montages (`montage/`)
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
# and retry a slice of artist images still missing one
php artisan lastfm:schedule

# Process the queue (send posts to Bluesky/Mastodon)
php artisan lastfm:send

# Force process + send for a single user
php artisan lastfm:force-send {user_id}

# One-time import of the legacy v1 database (automatic in Docker)
php artisan lastfm:import-legacy
```

Outside Docker, run the scheduler yourself:

```bash
php artisan schedule:work
```

---

**Made with ❤️ by [Butiá Labs](https://butialabs.com)**
