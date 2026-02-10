<?php

declare(strict_types=1);

return [
    'ships' => [
        'carrier' => [
            'base_range_ly' => 5.0,
            'per_level_bonus' => 0.5,
            'max_range_ly' => 7.0,
            'fuel_per_ly_factor' => 1.0,
        ],
        'dread' => [
            'max_range_ly' => 7.0,
            'fuel_per_ly_factor' => 1.1,
        ],
        'fax' => [
            'max_range_ly' => 7.0,
            'fuel_per_ly_factor' => 1.1,
        ],
        'super' => [
            'max_range_ly' => 7.0,
            'fuel_per_ly_factor' => 1.1,
        ],
        'titan' => [
            'max_range_ly' => 7.0,
            'fuel_per_ly_factor' => 1.1,
        ],
        'jump_freighter' => [
            'max_range_ly' => 10.0,
            'fuel_per_ly_factor' => 0.85,
        ],
    ],
];
