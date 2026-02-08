# Security

## Defaults
- CSRF protection on the web UI.
- Prepared statements for all SQL.
- Rate limiting for API endpoints.
- Least-privilege database user with SELECT/INSERT/UPDATE/DELETE only.

## Threat Model (v1)
- **Input abuse**: validated via strict validator and enum checks.
- **SQL injection**: mitigated with prepared statements.
- **Abuse/spam**: rate limiter per IP.
- **Secrets**: no secrets checked into repo; `.env` excluded.

## Recommended Hardening
- Terminate TLS at Nginx.
- Restrict DB user to application host.
- Enable WAF and CDN if exposed publicly.
