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

## Offline Precompute Jobs (SDE-stable)
These jobs are heavy and intended for long-running offline execution. They are resumable via DB checkpoints and safe to stop/restart. All outputs remain valid until the SDE/map changes.

### System facts (fast)
Recompute regional gates and NPC station flags:
```bash
php bin/console precompute:system-facts
```

### Gate hop distances (large)
Stores hop counts only (no full paths). To keep storage reasonable, the job limits stored hops to a maximum threshold (default 20). You can run for hours/days and resume.
```bash
# conservative default (1 hour)
php bin/console precompute:gate-distances --hours=1 --max-hops=20

# run for a day and resume as needed
php bin/console precompute:gate-distances --hours=24 --resume --max-hops=20

# limit to specific sources
php bin/console precompute:gate-distances --source-ids=30000142,30000144 --max-hops=15
```

### Jump neighbors (large)
Precomputes reachable neighbors within configured jump ranges and stores compressed adjacency blobs (`gzcompress`).
```bash
# default ranges from config/jump_ranges.php
php bin/console precompute:jump-neighbors --hours=1

# explicit ranges
php bin/console precompute:jump-neighbors --ranges=5,6,7,8,9,10 --hours=24 --resume
```

### Performance tuning knobs
- `--sleep=0.05` adds backoff between systems to reduce DB load.
- `--hours=0` disables the time limit.
- `--resume` continues from the last checkpoint in `precompute_checkpoints`.

### Operational notes
- Run precompute jobs after every SDE update (`sde:update` or `sde:install`).
- Gate distance usage at runtime is gated by the data present in `gate_distances`; missing data falls back to geometry heuristics.
