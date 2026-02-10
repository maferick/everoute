# API (v1)

Base: `/api/v1`

## POST /route

### Request JSON
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
  "avoid_strictness": "soft",
  "avoid_specific_systems": "Niarja",
  "prefer_npc_stations": false
}
```

### Parameters
| Field | Type | Default | Notes |
| --- | --- | --- | --- |
| `from` | string | required | Origin system name or ID. |
| `to` | string | required | Destination system name or ID. |
| `mode` | string | `subcap` | `subcap` or `capital`. Capital mode enables jump + hybrid planning. |
| `ship_class` | string | `subcap` | One of `subcap`, `interceptor`, `dst`, `freighter`, `capital`, `jump_freighter`, `super`, `titan`. |
| `jump_ship_type` | string | `carrier` | Used for jump range (capital/JF planning only). |
| `jump_skill_level` | number | `5` | Jump Drive Calibration level (0-5). |
| `safety_vs_speed` | number | `50` (`70` for capital) | Bias for risk vs speed. |
| `preference` | string | `shorter` | Gate-only tie-breaker: `shorter`, `safer`, or `less_secure`. |
| `avoid_lowsec` | boolean | `false` | Avoid low-sec space (soft penalty or hard filter depending on strictness). |
| `avoid_nullsec` | boolean | `false` | Avoid null-sec space (soft penalty or hard filter depending on strictness). |
| `avoid_strictness` | string | `soft` | `soft` applies penalties; `strict` filters low/null-sec from eligible systems with fallback to soft if no route is feasible. |
| `avoid_specific_systems` | string | empty | Comma-separated system names/IDs to exclude. |
| `prefer_npc_stations` | boolean | `false` (true for capital) | Adds a bonus to NPC-station systems for gate, jump, and hybrid planners. |
| `debug` | boolean | `false` | When enabled, adds debug statistics to jump planning responses. |

### Notes
- `jump_ship_type` and `jump_skill_level` are used for jump-assisted planning in Capital/JF mode.
- Hybrid planning uses gate-to-launch plus jump chain with optional landing gate segment. Configure hop limits with `HYBRID_LAUNCH_MAX_GATES` (default 6) and `HYBRID_LANDING_MAX_GATES` (default 3).
- Avoid low/null-sec settings are treated as soft penalties for jump planning unless `avoid_strictness=strict`.
- When routes are not feasible (e.g. capital start/end in high-sec), the response includes `error` and `reason`.
- When `APP_DEBUG=true`, jump planning responses include a `debug` object with candidate/edge counts and max segment distance.
- Jump fatigue calculations currently use `phoebe-2018-v1`; debug payloads and route-cache keys include this version for traceability.

### Response (truncated)
```json
{
  "from": {"id": 30000142, "name": "Jita"},
  "to": {"id": 30002187, "name": "Amarr"},
  "routes": {
    "balanced": {
      "total_jumps": 9,
      "risk_score": 12.4,
      "exposure_score": 5.2,
      "fallback_used": false,
      "space_types": ["highsec", "lowsec"],
      "rules": {
        "constraints": ["Rejected systems because: capital hulls cannot enter high-sec systems (sec >= 0.5)."],
        "jump": {
          "cooldown_minutes_estimate": 18.0,
          "fatigue_minutes_estimate": 64.5,
          "fatigue_risk": "medium",
          "jump_waits": [8.0, 10.0],
          "total_wait_minutes": 18.0
        }
      },
      "plans": {
        "gate": {
          "estimated_time_s": 540,
          "total_time_s": 540,
          "risk_score": 12.4,
          "exposure_score": 5.2,
          "total_jumps": 9,
          "lowsec_count": 2,
          "nullsec_count": 0,
          "npc_station_ratio": 0.22
        },
        "jump": {
          "feasible": true,
          "effective_jump_range_ly": 6,
          "estimated_time_s": 420,
          "jump_hops_count": 2,
          "jump_total_ly": 9.5,
          "jump_cooldown_total_minutes": 12,
          "jump_fatigue_estimate_minutes": 64.5,
          "jump_fatigue_risk_label": "low",
          "jump_waits": [6.0, 6.0],
          "total_wait_minutes": 12.0,
          "jump_segments": [{"from": "Jita", "to": "Niarja", "distance_ly": 4.2}],
          "midpoints": ["Niarja"],
          "debug": {
            "candidate_systems_evaluated": 1200,
            "edges_built": 640,
            "max_segment_distance_ly": 6.8,
            "chain_length": 2,
            "fatigue_model_version": "phoebe-2018-v1"
          }
        },
        "hybrid": {
          "feasible": true,
          "total_time_s": 510,
          "launch_system": {"name": "Perimeter", "used_regional_gate": true},
          "gate_segment": {"systems": ["Jita", "Perimeter"]},
          "jump_segment": {
            "jump_hops_count": 1,
            "jump_total_ly": 6.1,
            "jump_cooldown_total_minutes": 8.0,
            "jump_fatigue_risk_label": "low",
            "jump_waits": [8.0],
            "total_wait_minutes": 8.0
          },
          "reasons": ["Gated across region boundary to reposition."]
        },
        "recommended": {
          "best": "hybrid",
          "reason": "Hybrid plan offers the lowest total time estimate."
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

### Fallback example (strict avoidance)
```json
{
  "routes": {
    "safe": {
      "fallback_used": true,
      "space_types": ["highsec", "lowsec"],
      "plans": {
        "gate": {
          "total_jumps": 14,
          "lowsec_count": 3,
          "nullsec_count": 0,
          "npc_station_ratio": 0.29
        }
      }
    }
  }
}
```

### Response field highlights
| Field | Description |
| --- | --- |
| `fallback_used` | True when strict avoid rules were relaxed to return a route. |
| `space_types` | Ordered list of space types in the returned path. |
| `lowsec_count` / `nullsec_count` | Count of low-sec and null-sec systems in the path. |
| `npc_station_ratio` | NPC station count divided by total systems in the route. |
| `jump_waits` | Per-hop wait time estimates (minutes) before the next jump. |
| `total_wait_minutes` | Sum of wait times across the jump chain. |
| `wait_systems` | Systems where a wait is recommended before the next jump. |
| `wait_explanations` | Human-readable wait reasoning for each hop. |

## GET /system-risk?system=<nameOrId>
Returns system metadata and risk stats.

## GET /heatmap
Returns risk stats for all systems and update timestamp.

## GET /health
Returns `{ "status": "ok", "risk_provider": "manual|zkillredisq" }` plus risk update timestamps and ingestion heartbeat when available.
