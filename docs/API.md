# API (v1)

Base: `/api/v1`

## POST /route
Request JSON:
```json
{
  "from": "Jita",
  "to": "Amarr",
  "mode": "subcap",
  "ship_class": "subcap",
  "jump_ship_type": "carrier",
  "jump_skill_level": 4,
  "safety_vs_speed": 50,
  "avoid_lowsec": false,
  "avoid_nullsec": false,
  "avoid_specific_systems": "Niarja",
  "prefer_npc_stations": false
}
```

Notes:
- `jump_ship_type` and `jump_skill_level` are used for jump-assisted planning in Capital/JF mode.
- When routes are not feasible (e.g. capital start/end in high-sec), the response includes `error` and `reason`.

Response (truncated):
```json
{
  "from": {"id": 30000142, "name": "Jita"},
  "to": {"id": 30002187, "name": "Amarr"},
  "routes": {
    "balanced": {
      "total_jumps": 9,
      "risk_score": 12.4,
      "exposure_score": 5.2,
      "rules": {
        "constraints": ["Rejected systems because: capital hulls cannot enter high-sec systems (sec >= 0.5)."],
        "jump": {
          "cooldown_minutes_estimate": 18.0,
          "fatigue_risk": "medium"
        }
      },
      "plans": {
        "gate": {
          "estimated_time_s": 540,
          "risk_score": 12.4,
          "exposure_score": 5.2,
          "total_jumps": 9
        },
        "jump": {
          "feasible": true,
          "effective_jump_range_ly": 6,
          "estimated_time_s": 420,
          "cooldown_minutes_estimate": 12,
          "fatigue_hours_estimate": 1.5,
          "fatigue_risk": "low",
          "midpoints": ["Niarja"]
        }
      },
      "systems": [{"name": "Jita", "risk": 4.1}],
      "why": {
        "top_risk_systems": [{"name": "Jita", "score": 120}],
        "avoided_hotspots": ["Niarja"]
      },
      "midpoints": []
    }
  },
  "risk_updated_at": "2024-01-01 00:00:00"
}
```

## GET /system-risk?system=<nameOrId>
Returns system metadata and risk stats.

## GET /heatmap
Returns risk stats for all systems and update timestamp.

## GET /health
Returns `{ "status": "ok", "risk_provider": "manual|zkillredisq" }` plus risk update timestamps and ingestion heartbeat when available.
