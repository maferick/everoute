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
