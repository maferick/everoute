<?php

declare(strict_types=1);

namespace Everoute\Routing;

final class Graph
{
    /** @var array<int, int[]> */
    private array $edges = [];

    public function addEdge(int $from, int $to): void
    {
        $this->edges[$from][] = $to;
    }

    /** @return int[] */
    public function neighbors(int $node): array
    {
        return $this->edges[$node] ?? [];
    }
}
