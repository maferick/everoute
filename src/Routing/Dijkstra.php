<?php

declare(strict_types=1);

namespace Everoute\Routing;

use SplPriorityQueue;

final class Dijkstra
{
    /**
     * @param array<int, array<int, mixed>> $neighborsByNode
     * @param callable $costFn fn(int $from, int $to, mixed $edgeData): float
     * @param callable|null $allowFn fn(int $node): bool
     * @param array<int, bool>|null $allowedSet
     * @return array{distance: float, path: int[], edges: array<int, mixed>, status: string, nodes_explored: int}
     */
    public function shortestPath(
        array $neighborsByNode,
        int $start,
        int $goal,
        callable $costFn,
        ?callable $allowFn = null,
        ?array $allowedSet = null,
        int $maxNodes = 100000
    ): array {
        $dist = [$start => 0.0];
        $queue = new SplPriorityQueue();
        $queue->setExtractFlags(SplPriorityQueue::EXTR_DATA);
        $queue->insert($start, 0.0);
        $cameFrom = [];
        $nodesExplored = 0;
        $closed = [];
        $last = $start;

        while (!$queue->isEmpty()) {
            $current = $queue->extract();
            if (isset($closed[$current])) {
                continue;
            }
            $closed[$current] = true;
            $nodesExplored++;
            $last = $current;

            if ($nodesExplored > $maxNodes) {
                break;
            }

            if ($current === $goal) {
                return $this->reconstructPath($cameFrom, $current, $dist[$goal] ?? INF, $nodesExplored);
            }

            foreach ($neighborsByNode[$current] ?? [] as $neighborKey => $edgeData) {
                if (is_int($edgeData)) {
                    $neighbor = $edgeData;
                } elseif (is_array($edgeData) && isset($edgeData['to'])) {
                    $neighbor = (int) $edgeData['to'];
                } else {
                    $neighbor = (int) $neighborKey;
                }
                if (isset($closed[$neighbor])) {
                    continue;
                }

                if ($neighbor !== $goal) {
                    if ($allowedSet !== null && !isset($allowedSet[$neighbor])) {
                        continue;
                    }
                    if ($allowFn !== null && !$allowFn($neighbor)) {
                        continue;
                    }
                }

                $edgeCost = $costFn($current, $neighbor, $edgeData);
                if ($edgeCost === INF) {
                    continue;
                }
                $candidate = ($dist[$current] ?? INF) + $edgeCost;
                if (!isset($dist[$neighbor]) || $candidate < $dist[$neighbor]) {
                    $dist[$neighbor] = $candidate;
                    $cameFrom[$neighbor] = ['prev' => $current, 'edge' => $edgeData];
                    $queue->insert($neighbor, -$candidate);
                }
            }
        }

        $distance = $dist[$last] ?? INF;

        return $this->reconstructPath($cameFrom, $last, $distance, $nodesExplored, $last === $goal);
    }

    private function reconstructPath(array $cameFrom, int $current, float $distance, int $nodesExplored, bool $success = true): array
    {
        if ($current === null) {
            return [
                'distance' => INF,
                'path' => [],
                'edges' => [],
                'status' => 'failed',
                'nodes_explored' => $nodesExplored,
            ];
        }

        $path = [$current];
        $edges = [];
        while (isset($cameFrom[$current])) {
            $edge = $cameFrom[$current]['edge'] ?? null;
            $edges[] = $edge;
            $current = $cameFrom[$current]['prev'];
            array_unshift($path, $current);
        }
        $edges = array_reverse($edges);

        return [
            'distance' => $distance,
            'path' => $path,
            'edges' => $edges,
            'status' => $success ? 'success' : 'partial',
            'nodes_explored' => $nodesExplored,
        ];
    }
}
