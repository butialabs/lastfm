# LastFM.blue

[![Docker](https://img.shields.io/badge/Docker-Ready-blue?logo=docker)](https://ghcr.io/butialabs/lastfm)
[![PHP](https://img.shields.io/badge/PHP-8.4+-purple?logo=php)](https://php.net)

**LastFM.blue** that automates weekly posting of your Last.fm *Weekly Artist Chart* to **Bluesky (AT Protocol)** and **Mastodon**.

🌐 **Public Instance:** [https://lastfm.blue](https://lastfm.blue)

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
      LASTFM_API: your_lastfm_api_key
      ENCRYPTION_KEY: your_32_character_encryption_key
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

4. Access the application at `http://localhost` (or your configured domain)

### Environment Variables

| Variable | Description | Required |
|----------|-------------|----------|
| `APP_URL` | Public URL of your instance | Yes |
| `LASTFM_API` | Your Last.fm API key ([Get one here](https://www.last.fm/api/account/create)) | Yes |
| `ENCRYPTION_KEY` | 32-character key for encrypting credentials | Yes |
| `ADMIN_USER` | Admin panel username | Yes |
| `ADMIN_PASSWORD` | Admin panel password | Yes |
| `TZ` | Timezone (e.g., `America/Sao_Paulo`) | No |

### Persistent Data

Mount the `/app/data` volume to persist:
- SQLite database
- Artist image cache
- Generated montages
- Application logs

---

## 👨‍💻 Developer Installation

For local development or contributing to the project.

### Requirements

- PHP >= 8.3
- PHP Extensions: `pdo_sqlite`, `sqlite3`, `gd`
- Composer
- Node.js & npm (for asset compilation)

### Setup

1. Clone the repository:

```bash
git clone https://github.com/butialabs/lastfm.git
cd lastfm/app
```

2. Install PHP/Node:

```bash
composer install
npm install
```

4. Environment:

```bash
cp .env.example .env
```

Edit `.env` with your settings:

```env
APP_URL=http://localhost:8080
DB_CONNECTION=sqlite
SQLITE_PATH=data/db/lastfm.sqlite
LASTFM_API=your_lastfm_api_key
ENCRYPTION_KEY=your_32_character_encryption_key
ADMIN_USER=admin
ADMIN_PASSWORD=your_password
```

5. Run database migrations:

```bash
vendor/bin/phinx migrate -c phinx.php
```

6. Compile assets (optional):

```bash
npx gulp
```

7. Start the development server:

```bash
php -S localhost:8080 -t public
```

8. Open `http://localhost:8080` in your browser

---

## 🔧 CLI Commands


```bash
# Process scheduled users (generate montages and mark as QUEUED)
php bin/lastfm users --schedule

# Process queue (send posts to Bluesky/Mastodon)
php bin/lastfm users --send
```

---

**Made with ❤️ by [Butiá Labs](https://butialabs.com)**