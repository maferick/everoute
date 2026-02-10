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
| `safety_vs_speed` | number | `50` (`70` for capital) | Bias for risk vs speed; drives `w_time`, `w_risk`, and `w_pref` scoring weights. |
| `preference` | string | `shorter` | Gate-only tie-breaker: `shorter`, `safer`, or `less_secure`. |
| `avoid_lowsec` | boolean | `false` | Avoid low-sec space (soft penalty or hard filter depending on strictness). |
| `avoid_nullsec` | boolean | `false` | Avoid null-sec space (soft penalty or hard filter depending on strictness). |
| `avoid_strictness` | string | `soft` | `soft` applies penalties; `strict` filters low/null-sec, then automatically retries with soft semantics when no feasible route exists. |
| `avoid_specific_systems` | string | empty | Comma-separated system names/IDs to exclude. |
| `prefer_npc_stations` | boolean | `false` (true for capital) | Enables NPC preference behavior: route-level `npc_bonus` + `preference_cost` for gate, jump, and hybrid planners. |
| `debug` | boolean | `false` | When enabled, adds debug statistics to jump planning responses. |

### Notes
- `jump_ship_type` and `jump_skill_level` are used for jump-assisted planning in Capital/JF mode.
- Hybrid planning uses gate-to-launch plus jump chain with optional landing gate segment. Configure hop limits with `HYBRID_LAUNCH_MAX_GATES` (default 6) and `HYBRID_LANDING_MAX_GATES` (default 3).
- Weighting model uses `safety_vs_speed` (`s=safety_vs_speed/100`): `w_time=1.2-0.7*s`, `w_risk=0.2+1.2*s`, `w_pref=0.15+0.1*s`.
- Scoring total is `time_cost*w_time + risk_cost*w_risk + preference_cost*w_pref + npc_bonus`.
- Strict avoid fallback semantics: with `avoid_strictness=strict`, the engine first filters avoided security bands; if no feasible route is found, it retries with soft penalties and returns `fallback_used=true`, `requested_avoid_strictness=strict`, `applied_avoid_strictness=soft`.
- In speed-leaning mode (`safety_vs_speed<=25`), jump can dominate hybrid when `jump.time_cost <= hybrid.time_cost*0.95`.
- In speed-leaning comparisons with similar time, extra-gate dominance penalties can be added for routes with ≥2 extra gates and `time_delta<=0.1`.
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

### Example snippet (cost breakdown + selection reason)
```json
{
  "routes": {
    "balanced": {
      "best_selection_reason": "normalized_total_cost_with_extra_gate_penalty",
      "plans": {
        "jump": {
          "time_cost": 0.18,
          "risk_cost": 0.31,
          "preference_cost": 0.22,
          "total_cost": 0.4123,
          "weights_used": {"w_time": 0.99, "w_risk": 0.56, "w_pref": 0.18},
          "penalties_bonuses": {
            "npc_bonus": -0.11,
            "selection_penalty": 0.04,
            "extra_gate_penalty": 0.04,
            "cooldown_cap_penalty_minutes": 0
          },
          "dominance_flags": {
            "selected_as_best": true,
            "dominance_rule_applied": false,
            "dominance_rule_winner": false,
            "extra_gate_penalty_applied": true
          }
        }
      }
    }
  }
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
| `requested_avoid_strictness` / `applied_avoid_strictness` | Requested strictness and the strictness actually used for the returned route. |
| `space_types` | Ordered list of space types in the returned path. |
| `lowsec_count` / `nullsec_count` | Count of low-sec and null-sec systems in the path. |
| `npc_station_ratio` | NPC station count divided by total systems in the route. |
| `time_cost` | Normalized travel-time component (`gates + LY`, capped to 1.0). |
| `risk_cost` | Normalized risk component derived from route security penalties. |
| `preference_cost` | NPC preference component (`1 - npc_station_ratio`) when `prefer_npc_stations=true`; otherwise `0`. |
| `npc_bonus` | Additional negative cost bonus applied when `prefer_npc_stations=true`. |
| `weights_used` | Effective scoring weights (`w_time`, `w_risk`, `w_pref`) from `safety_vs_speed`. |
| `penalties_bonuses` | Selection penalties/bonuses (`npc_bonus`, `selection_penalty`, `extra_gate_penalty`, cooldown cap penalty). |
| `best_selection_reason` | Why the route type was chosen (normalized cost, dominance threshold, or extra-gate penalty path). |
| `dominance_flags` | Selection diagnostics (`selected_as_best`, dominance-rule, and extra-gate penalty flags). |
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

## Async route jobs

### `POST /api/v1/route-jobs`
Creates a route calculation job and returns immediately.

Response (`202`):
```json
{
  "job_id": "uuid",
  "status": "queued",
  "poll_url": "/api/v1/route-jobs/uuid"
}
```

### `GET /api/v1/route-jobs/{job_id}`
Returns job status, progress, and result when available.

### `DELETE /api/v1/route-jobs/{job_id}`
Attempts to cancel a queued/running job.
