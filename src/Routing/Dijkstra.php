<?php

declare(strict_types=1);

namespace Everoute\Routing;

use SplPriorityQueue;

final class Dijkstra
{
    /**
     * @param callable $costFn fn(int $from, int $to): float
     * @return array{distance: float, path: int[]}
     */
    public function shortestPath(Graph $graph, int $start, int $goal, callable $costFn): array
    {
        $dist = [];
        $prev = [];
        $queue = new SplPriorityQueue();
        $queue->setExtractFlags(SplPriorityQueue::EXTR_DATA);
        $dist[$start] = 0.0;
        $queue->insert($start, 0.0);

        while (!$queue->isEmpty()) {
            $current = $queue->extract();
            if ($current === $goal) {
                break;
            }

            foreach ($graph->neighbors($current) as $neighbor) {
                $alt = $dist[$current] + $costFn($current, $neighbor);
                if (!isset($dist[$neighbor]) || $alt < $dist[$neighbor]) {
                    $dist[$neighbor] = $alt;
                    $prev[$neighbor] = $current;
                    $queue->insert($neighbor, -$alt);
                }
            }
        }

        if (!isset($dist[$goal])) {
            return ['distance' => INF, 'path' => []];
        }

        $path = [$goal];
        $node = $goal;
        while (isset($prev[$node])) {
            $node = $prev[$node];
            array_unshift($path, $node);
        }

        return ['distance' => $dist[$goal], 'path' => $path];
    }
}
