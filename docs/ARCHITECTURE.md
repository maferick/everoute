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
- Capital/JF mode also evaluates jump-only and hybrid (gate-to-launch + jump chain + optional landing gate) plans.

## Routing Policy (Avoidance + Preferences)
- **Soft avoidance** (`avoid_strictness=soft`) applies penalties for low/null-sec systems instead of removing them. This is the default for capital and jump planning, so a viable chain can still be found if the only way out crosses low/null-sec.
- **Strict avoidance** (`avoid_strictness=strict`) removes low/null-sec systems from the candidate set. If no route is feasible, the engine automatically relaxes to soft avoidance and returns `fallback_used=true` for the affected route.
- **NPC station preference** adds a bonus to systems that have NPC stations; this bonus is applied consistently for gate, jump, and hybrid planners. Gate routing will lean toward NPC-station systems. Jump routing uses the same bonus when evaluating midpoint candidates. Hybrid routing respects the bonus for both the gate launch/landing segments and the jump chain.
- **Hybrid multi-phase planning** evaluates: (1) a gate segment to a launch system, (2) the jump chain, and (3) an optional landing gate segment. If the launch system is in a different region, the planner may add a short gate repositioning step (regional-gate hop) to move across the region boundary before jumping.

## Jump Fatigue Model (v1)
- Deterministic per-jump fatigue score based on jump distance.
- Each jump accrues base fatigue + distance factor, with caps applied (fatigue max 300 minutes).
- Activation cooldown scales with distance and current fatigue, capped at 30 minutes.
- Risk labels: low (<60 min), medium (60-179 min), high (>=180 min).
- Per-hop wait recommendations are derived from the activation cooldown; the model returns both per-hop waits and a total wait estimate for chains.

## Explainability
- Top risk systems list.
- Avoided hotspots versus fastest path.
- Tradeoff summary and data freshness.
