<?php

declare(strict_types=1);

namespace Everoute\Routing;

final class JumpRangeCalculator
{
    private array $config;

    public function __construct(string $configPath)
    {
        $config = require $configPath;
        $this->config = is_array($config) ? $config : [];
    }

    public function effectiveRange(string $shipType, int $skillLevel): ?float
    {
        $shipType = strtolower($shipType);
        $base = $this->config['base_ranges_ly'][$shipType] ?? null;
        if ($base === null) {
            return null;
        }

        $skillLevel = max(0, min(5, $skillLevel));
        $multiplier = (float) ($this->config['skill_multiplier_per_level'] ?? 0.0);
        $effective = (float) $base * (1.0 + $skillLevel * $multiplier);

        return round($effective, 2);
    }

    /** @return float[] */
    public function rangeBuckets(): array
    {
        $ranges = [];
        $multiplier = (float) ($this->config['skill_multiplier_per_level'] ?? 0.0);
        foreach ($this->config['base_ranges_ly'] ?? [] as $base) {
            for ($skillLevel = 0; $skillLevel <= 5; $skillLevel++) {
                $ranges[] = round(((float) $base) * (1.0 + $skillLevel * $multiplier), 2);
            }
        }
        $ranges = array_values(array_unique($ranges, SORT_NUMERIC));
        sort($ranges, SORT_NUMERIC);
        return $ranges;
    }
}
