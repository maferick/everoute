<?php

declare(strict_types=1);

namespace Everoute\Routing;

use SplPriorityQueue;

final class AStar
{
    /**
     * @param array<int, array<int, int|float>|int[]> $neighborsByNode
     * @param callable $costFn fn(int $from, int $to, mixed $edgeData): float
     * @param callable $heuristicFn fn(int $node): float
     * @param callable|null $allowFn fn(int $node): bool
     * @param array<int, bool>|null $allowedSet
     * @return array{distance: float, path: int[], status: string, nodes_explored: int, duration_ms: float}
     */
    public function shortestPath(
        array $neighborsByNode,
        int $start,
        int $goal,
        callable $costFn,
        callable $heuristicFn,
        ?callable $allowFn = null,
        ?array $allowedSet = null,
        int $maxNodes = 1500,
        float $maxSeconds = 2.0
    ): array {
        $startTime = microtime(true);
        $gScore = [$start => 0.0];
        $fScore = [$start => $heuristicFn($start)];
        $queue = new SplPriorityQueue();
        $queue->setExtractFlags(SplPriorityQueue::EXTR_DATA);
        $queue->insert($start, -$fScore[$start]);
        $cameFrom = [];
        $nodesExplored = 0;
        $bestNode = $start;
        $bestHeuristic = $fScore[$start];

        while (!$queue->isEmpty()) {
            $elapsed = microtime(true) - $startTime;
            if ($nodesExplored >= $maxNodes || $elapsed >= $maxSeconds) {
                break;
            }

            $current = $queue->extract();
            $nodesExplored++;

            if ($current === $goal) {
                $path = $this->reconstructPath($cameFrom, $current);
                return [
                    'distance' => $gScore[$goal],
                    'path' => $path,
                    'status' => 'success',
                    'nodes_explored' => $nodesExplored,
                    'duration_ms' => ($elapsed * 1000),
                ];
            }

            $currentHeuristic = $heuristicFn($current);
            if ($currentHeuristic < $bestHeuristic) {
                $bestHeuristic = $currentHeuristic;
                $bestNode = $current;
            }

            foreach ($neighborsByNode[$current] ?? [] as $neighborKey => $edgeData) {
                if (is_int($edgeData)) {
                    $neighbor = $edgeData;
                    $edgeData = null;
                } else {
                    $neighbor = (int) $neighborKey;
                }

                if ($neighbor !== $goal) {
                    if ($allowedSet !== null && !isset($allowedSet[$neighbor])) {
                        continue;
                    }
                    if ($allowFn !== null && !$allowFn($neighbor)) {
                        continue;
                    }
                }

                $tentative = $gScore[$current] + $costFn($current, $neighbor, $edgeData);
                if (!isset($gScore[$neighbor]) || $tentative < $gScore[$neighbor]) {
                    $cameFrom[$neighbor] = $current;
                    $gScore[$neighbor] = $tentative;
                    $fScore[$neighbor] = $tentative + $heuristicFn($neighbor);
                    $queue->insert($neighbor, -$fScore[$neighbor]);
                }
            }
        }

        $elapsed = microtime(true) - $startTime;
        $partialPath = $this->reconstructPath($cameFrom, $bestNode);

        return [
            'distance' => $gScore[$bestNode] ?? INF,
            'path' => $partialPath,
            'status' => $partialPath === [] ? 'failed' : 'partial',
            'nodes_explored' => $nodesExplored,
            'duration_ms' => ($elapsed * 1000),
        ];
    }

    /** @return int[] */
    private function reconstructPath(array $cameFrom, int $current): array
    {
        $path = [$current];
        while (isset($cameFrom[$current])) {
            $current = $cameFrom[$current];
            array_unshift($path, $current);
        }
        return $path;
    }
}
