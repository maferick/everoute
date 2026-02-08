# Installation

## Requirements
- PHP 8.3+ with PDO
- MariaDB 10.6+
- Nginx + PHP-FPM

## Install Steps
```bash
composer install --no-dev
cp .env.example .env
php bin/console install --admin-user root --admin-pass <password>
php bin/console sde:install
php bin/console precompute:system-facts
php bin/console precompute:jump-neighbors --hours=1
php bin/console seed:chokepoints
php bin/console import:risk --file data/risk.json
```

## Schema-only (existing DB/user)
```bash
php bin/console install --schema-only --app-user everoute_app --app-pass <password>
```

## SDE Configuration
Set optional environment values in `.env`:
- `SDE_STORAGE_PATH` (default `/var/lib/everoute/sde`)
- `SDE_VARIANT` (default `jsonl`)
- `SDE_BASE_URL` (default `https://developers.eveonline.com/static-data/tranquility`)
- `SDE_TIMEOUT` (seconds, default `60`)
- `SDE_RETRIES` (default `3`)

## Nginx
See `scripts/nginx-example.conf`.

## Import Universe Data
```bash
php bin/console sde:install
```

## Legacy Universe Import
```bash
php bin/console import:universe --file data/universe.json
```

## Seed Chokepoints
```bash
php bin/console seed:chokepoints
```

## Import Risk Data
```bash
php bin/console import:risk --file data/risk.json
```

## Optional zKillboard Fetch
```bash
php bin/console risk:fetch --limit 200
```
