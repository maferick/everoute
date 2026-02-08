# Everoute

Everoute is a production-ready v1 route planning platform for EVE Online, focused on explainable risk-aware routing, exposure-time optimization, and capital/JF safety intelligence.

## Features
- Explainable routing with risk, exposure, and infrastructure tradeoffs.
- Exposure-time proxy (system size + ship class modifiers).
- Capital/JF midpoints and NPC station bias.
- Public JSON API.
- CLI installer and importers.

## Quick Start
```bash
composer install
cp .env.example .env
php bin/console install --schema-only
php -S localhost:8080 -t public
```

## Documentation
- [Product Requirements](docs/PRD.md)
- [Architecture](docs/ARCHITECTURE.md)
- [API](docs/API.md)
- [Install](docs/INSTALL.md)
- [Operations](docs/OPERATIONS.md)
- [Security](docs/SECURITY.md)
- [Privacy](docs/PRIVACY.md)
- [Roadmap](docs/ROADMAP.md)

## License
MIT
