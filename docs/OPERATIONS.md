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

## Cache Warming
```bash
php bin/console cache:warm
```
