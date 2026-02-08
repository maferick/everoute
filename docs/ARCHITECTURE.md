# Architecture

## Overview
Everoute is a lightweight PHP 8.3 application with a minimal routing engine.

## Layers
- **HTTP**: `public/index.php` with API routing and UI.
- **Routing**: Dijkstra-based shortest path with dynamic weight profiles.
- **Universe**: Systems, stargates, stations imported from user-provided data.
- **Risk**: Aggregated kill stats, chokepoints, and freshness timestamps.
- **Security**: CSRF for web UI, rate limiting for API, input validation.

## Data Model
- `systems`, `stargates`, `stations` for topology.
- `system_risk` and `chokepoints` for risk intelligence.
- `route_cache` optional.

## Caching Strategy
- Data is loaded in-memory per request.
- Optional `route_cache` table for future route memoization.

## Routing Algorithm
- Dijkstra on gate graph.
- Cost = travel + risk + exposure + infrastructure.
- Three presets re-run with different weight profiles.

## Explainability
- Top risk systems list.
- Avoided hotspots versus fastest path.
- Tradeoff summary and data freshness.
