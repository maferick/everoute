<?php

declare(strict_types=1);

namespace Everoute\Routing;

final class JumpRangeCalculator
{
    private array $shipsConfig;
    private array $rangeConfig;

    public function __construct(string $shipsConfigPath, ?string $rangeConfigPath = null)
    {
        $ships = require $shipsConfigPath;
        $this->shipsConfig = is_array($ships) ? $ships : [];
        $rangeConfigPath ??= $shipsConfigPath;
        $range = require $rangeConfigPath;
        $this->rangeConfig = is_array($range) ? $range : [];
    }

    public function effectiveRange(string $shipType, int $skillLevel): ?float
    {
        $shipType = JumpShipType::normalizeJumpShipType($shipType);
        $ship = $this->shipsConfig['ships'][$shipType] ?? null;
        if (!is_array($ship)) {
            return null;
        }

        $base = $ship['base_range_ly'] ?? $ship['max_range_ly'] ?? null;
        $max = $ship['max_range_ly'] ?? $base;
        if ($base === null || $max === null) {
            return null;
        }

        $skillLevel = max(0, min(5, $skillLevel));
        $perLevel = (float) ($ship['per_level_bonus'] ?? 0.0);
        $effective = (float) $base + ($skillLevel * $perLevel);
        $effective = min((float) $max, $effective);

        return round($effective, 2);
    }

    public function fuelPerLyFactor(string $shipType): ?float
    {
        $shipType = JumpShipType::normalizeJumpShipType($shipType);
        $ship = $this->shipsConfig['ships'][$shipType] ?? null;
        if (!is_array($ship) || !array_key_exists('fuel_per_ly_factor', $ship)) {
            return null;
        }

        $factor = (float) $ship['fuel_per_ly_factor'];
        return $factor >= 0.0 ? $factor : null;
    }

    /** @return float[] */
    public function rangeBuckets(): array
    {
        $ranges = [];
        $configured = $this->rangeConfig['range_buckets_ly'] ?? null;
        if (is_array($configured)) {
            foreach ($configured as $value) {
                if (is_numeric($value)) {
                    $ranges[] = (float) $value;
                }
            }
        }

        $ranges = array_filter($ranges, static fn (float $range): bool => $range >= 1.0 && $range <= 10.0);
        $ranges = array_values(array_unique($ranges, SORT_NUMERIC));
        sort($ranges, SORT_NUMERIC);
        return $ranges;
    }

    public function neighborCapPerSystem(): int
    {
        $cap = (int) ($this->rangeConfig['neighbor_cap_per_system'] ?? 2000);
        return max(1, $cap);
    }

    public function neighborStorageWarningBytes(): int
    {
        $limit = (int) ($this->rangeConfig['neighbor_storage_warning_bytes'] ?? 0);
        return max(0, $limit);
    }
}
