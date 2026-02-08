# Everoute PRD (v1)

## Mission
Build the navigation standard for EVE Online: explainable routing, exposure-aware travel time proxy, and capital/JF safety intelligence.

## Core User Flows
1. Select start and destination systems.
2. Choose mode: hauling, subcap, capital.
3. Choose ship class preset.
4. Optional preferences: avoid low/null, avoid systems, prefer NPC stations, safety vs speed slider.
5. Receive three routes (Fast/Balanced/Safe) with explainability.

## Key Outputs
- Route list with per-system risk and flags.
- Total jumps, exposure score, risk score, travel time proxy.
- “Why this route?” explanation.
- Capital/JF midpoint suggestions and abort candidates.

## Non-Functional
- Nginx + PHP-FPM friendly.
- Prepared statements and rate limiting.
- Data importer for universe and risk data.
