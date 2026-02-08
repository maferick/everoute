<?php

declare(strict_types=1);

namespace Everoute\Routing;

final class JumpNeighborGraphBuilder
{
    public function buildSpatialBuckets(array $systems, float $rangeMeters): array
    {
        $bucketSize = max(1.0, $rangeMeters);
        $buckets = [];
        foreach ($systems as $id => $system) {
            $bucketKey = $this->bucketKey($system, $bucketSize);
            $buckets[$bucketKey][] = (int) $id;
        }

        return [
            'size' => $bucketSize,
            'buckets' => $buckets,
        ];
    }

    /** @return array<int, float> */
    public function buildNeighborsForSystem(array $system, array $systems, array $bucketIndex, float $rangeMeters): array
    {
        $bucketSize = $bucketIndex['size'];
        $bx = (int) floor(((float) $system['x']) / $bucketSize);
        $by = (int) floor(((float) $system['y']) / $bucketSize);
        $bz = (int) floor(((float) $system['z']) / $bucketSize);
        $neighbors = [];

        for ($dx = -1; $dx <= 1; $dx++) {
            for ($dy = -1; $dy <= 1; $dy++) {
                for ($dz = -1; $dz <= 1; $dz++) {
                    $key = ($bx + $dx) . ':' . ($by + $dy) . ':' . ($bz + $dz);
                    foreach ($bucketIndex['buckets'][$key] ?? [] as $candidateId) {
                        if ($candidateId === (int) $system['id']) {
                            continue;
                        }
                        $candidate = $systems[$candidateId] ?? null;
                        if ($candidate === null) {
                            continue;
                        }
                        if (
                            abs(((float) $system['x']) - (float) $candidate['x']) > $rangeMeters
                            || abs(((float) $system['y']) - (float) $candidate['y']) > $rangeMeters
                            || abs(((float) $system['z']) - (float) $candidate['z']) > $rangeMeters
                        ) {
                            continue;
                        }
                        $distanceMeters = JumpMath::distanceMeters($system, $candidate);
                        if ($distanceMeters <= $rangeMeters) {
                            $neighbors[$candidateId] = $distanceMeters / JumpMath::METERS_PER_LY;
                        }
                    }
                }
            }
        }

        return $neighbors;
    }

    private function bucketKey(array $system, float $bucketSize): string
    {
        $bx = (int) floor(((float) $system['x']) / $bucketSize);
        $by = (int) floor(((float) $system['y']) / $bucketSize);
        $bz = (int) floor(((float) $system['z']) / $bucketSize);
        return $bx . ':' . $by . ':' . $bz;
    }
}
