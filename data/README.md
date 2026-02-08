# Data Inputs

Provide universe and risk datasets as JSON files.

## Universe JSON Format
```json
{
  "systems": [
    {
      "id": 30000142,
      "name": "Jita",
      "security": 0.9,
      "region_id": 10000002,
      "constellation_id": 20000020,
      "x": 0,
      "y": 0,
      "z": 0,
      "system_size_au": 1.2
    }
  ],
  "stargates": [
    {"from_system_id": 30000142, "to_system_id": 30000144}
  ],
  "stations": [
    {"station_id": 60003760, "system_id": 30000142, "name": "Jita IV - Moon 4", "type": "NPC", "is_npc": 1}
  ]
}
```

## Risk JSON Format
```json
[
  {
    "system_id": 30000142,
    "kills_last_1h": 4,
    "kills_last_24h": 120,
    "pod_kills_last_1h": 1,
    "pod_kills_last_24h": 12,
    "last_updated_at": "2024-01-01 00:00:00"
  }
]
```
