# Jump Mechanics Notes

Everoute's capital jump planning follows EVE Online mechanics references:
- EVE University Jump Drives overview: https://wiki.eveuniversity.org/Jump_drives
- CCP Jump Activation Cooldown and Jump Fatigue: https://support.eveonline.com/hc/en-us/articles/212726865-Jump-Activation-Cooldown-and-Jump-Fatigue
- CCP fatigue/cooldown caps (5h fatigue, 30m cooldown): https://www.eveonline.com/news/view/sovereignty-warfare-and-jump-fatigue-changes-coming-in-the-march-release

## Distance & Range
- System coordinates are treated as meters (SDE convention).
- Euclidean distance is converted to light-years using:
  - 1 LY = 9.4607304725808e15 meters.
- Effective jump range is computed from base hull ranges plus Jump Drive Calibration skill levels (0-5).

## Jump Fatigue & Cooldown (Estimator)
- Each jump adds base fatigue plus a distance-based factor.
- Activation cooldown scales with distance and current fatigue.
- Caps applied:
  - Fatigue ≤ 300 minutes (5 hours).
  - Activation cooldown ≤ 30 minutes.
- Wait-time guidance:
  - The planner provides per-hop wait estimates (`jump_waits`) and a chain total (`total_wait_minutes`).
  - Recommended waits are based on the activation cooldown after each hop; the final hop typically has no wait requirement.
  - If cumulative fatigue approaches the cap, consider pausing until fatigue drops below 60 minutes to reduce risk labels.

These estimates are deterministic and meant for planning; they should align with CCP guidance for relative comparisons, not serve as an in-client timer replacement.

- Fatigue model version: `phoebe-2018-v1` (used in debug output and route-cache keys).
