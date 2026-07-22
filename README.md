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
      APP_URL: https://your-domain.com
      APP_KEY: base64:your_app_key # optional, auto-generated on first boot
      LASTFM_API: your_lastfm_api_key
      ADMIN_USER: admin
      ADMIN_PASSWORD: your_secure_password
    ports:
      - 80:80
    volumes:
      - ./lastfm/data:/app/data
    restart: unless-stopped
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
| `ADMIN_USER` | Initial admin username (seeded on first boot) | Yes |
| `ADMIN_PASSWORD` | Initial admin password (seeded on first boot, stored hashed) | Yes |
| `APP_KEY` | Laravel encryption key (`php artisan key:generate --show`). Auto-generated and persisted in the data volume if omitted | No |
| `ENCRYPTION_KEY` | **Upgrades from v1 only**: legacy key used to decrypt old credentials during the one-time import. Can be removed afterwards | No |
| `TZ` | Timezone (e.g., `America/Sao_Paulo`) | No |
| `LASTFM_PROXY_URL` | Proxy fallback for Last.fm image scraping (see below) | No |
| `THEAUDIODB_API_KEY` / `FANART_API_KEY` | Alternative artist image providers for the admin panel | No |
| `MAX_ERROR_COUNT` | Send attempts before giving up until next week (default `3`) | No |

> **APP_KEY stability:** encrypted credentials (Bluesky app passwords / Mastodon tokens) are tied to `APP_KEY`. Once set, by you or auto-generated into `./lastfm/data/.app_key`, never change it, or users will need to log in again.

### Upgrading from v1 (custom PHP app)

Keep `ENCRYPTION_KEY` (the old 32-char key) set on the **first boot** of the new version.
After a successful import, `ENCRYPTION_KEY` can be removed from the environment.

### Proxy fallback

In 2019, Last.fm removed the image API, so artist images have to be scraped from the public artist page. Under heavy traffic Last.fm will block requests (`403`/`429`). The service uses a simple two-stage fallback:

**Direct (1 attempt) → Proxy (2 attempts).**

- The direct attempt rotates User-Agents and sets full browser headers to reduce bot-detection hits.
- If it fails and `LASTFM_PROXY_URL` is set, the request is retried up to twice through that single proxy.
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

- PHP >= 8.4 with `pdo_sqlite`, `sqlite3`, `gd`
- Composer

### Setup

```bash
cd lastfm/app
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed --class=AdminSeeder
php artisan serve
```

Edit `.env` with your settings (`LASTFM_API`, `ADMIN_USER`, `ADMIN_PASSWORD`, ...).

Run the test suite (Pest):

```bash
php artisan test
```

---

## 🔧 CLI Commands

```bash
# Process scheduled users (generate montages and mark as QUEUED)
php artisan lastfm:schedule

# Process the queue (send posts to Bluesky/Mastodon)
php artisan lastfm:send

# Download missing artist images
php artisan lastfm:images-download

# Force process + send for a single user
php artisan lastfm:force-send {user_id}

# One-time import of the legacy v1 database (automatic in Docker)
php artisan lastfm:import-legacy
```

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

---

**Made with ❤️ by [Butiá Labs](https://butialabs.com)**
