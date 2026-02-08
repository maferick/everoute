<?php

declare(strict_types=1);

return [
    // Precomputed jump range buckets (LY). Only values 1-10 are supported.
    'range_buckets_ly' => range(1, 10),
    // Max neighbors stored per system per range bucket.
    'neighbor_cap_per_system' => 1500,
    // Warn when total neighbor storage exceeds this byte threshold.
    'neighbor_storage_warning_bytes' => 250_000_000,
];
