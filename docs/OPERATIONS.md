# Operations

## Deployment
- Use Nginx + PHP-FPM.
- Configure `.env` with database credentials.
- Keep write permissions for session storage and temp directory (rate limiter).

## Monitoring
- Application logs are JSON lines emitted to stderr.
- `/api/v1/health` provides liveness.

## Updating Risk Data
- Schedule `php bin/console import:risk` via cron.
- Optional: schedule `php bin/console risk:fetch` for live zKillboard snapshots (rate limit responsibly).
- For live killfeed aggregation, run `php bin/console risk:ingest` continuously with `RISK_PROVIDER=zkillredisq`.
- Prune old kill events daily with `php bin/console risk:prune` (retention is `RISK_EVENT_RETENTION_HOURS`).

### zKillboard RedisQ ingestion
Use a unique queue ID per environment (e.g. `routing_lonewolves_prod`). RedisQ enforces one request at a time per queue ID and ~2 req/sec per IP. The ingest loop respects `RISK_ZKILL_TTW` (1-10) and backs off on 429s.

Supported `RISK_PROVIDER` values:
- `manual` (imported or static data)
- `zkillredisq` (live RedisQ ingestion)
- `zkillws` (stub for future websocket ingestion)
- `everef` (stub for dataset backfills)

Example systemd unit:
```ini
[Unit]
Description=Everoute Risk Ingestion
After=network.target mariadb.service

[Service]
Type=simple
WorkingDirectory=/var/www/everoute
EnvironmentFile=/var/www/everoute/.env
ExecStart=/usr/bin/php /var/www/everoute/bin/console risk:ingest
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Example cron for pruning:
```cron
0 * * * * /usr/bin/php /var/www/everoute/bin/console risk:prune
```

## Updating SDE Data
- Recommended cadence: check daily and update weekly (or when CCP releases a new build).
- `sde:update` performs a full reimport in v1; re-run `seed:chokepoints` afterwards if needed.
- Clean up old archives with `php bin/console sde:cleanup --days 14`.

Sample cron:
```cron
# daily check
0 2 * * * /usr/bin/php /var/www/everoute/bin/console sde:check
# weekly update (full reimport for v1)
0 3 * * 0 /usr/bin/php /var/www/everoute/bin/console sde:update
```

## Cache Warming
```bash
php bin/console cache:warm
```
