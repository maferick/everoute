<?php

declare(strict_types=1);

return [
    // Precomputed jump range buckets (LY). Only values 1-10 are supported.
    'range_buckets_ly' => range(1, 10),
    // Base ranges in light-years (LY) before skills.
    'base_ranges_ly' => [
        'carrier' => 5.0,
        'dread' => 5.0,
        'fax' => 5.0,
        'jump_freighter' => 10.0,
        'supercarrier' => 5.0,
        'titan' => 5.0,
    ],
    // Jump Drive Calibration-style multiplier per skill level (0-5).
    'skill_multiplier_per_level' => 0.2,
    // Max neighbors stored per system per range bucket.
    'neighbor_cap_per_system' => 2000,
    // Warn when total neighbor storage exceeds this byte threshold.
    'neighbor_storage_warning_bytes' => 250_000_000,
];
