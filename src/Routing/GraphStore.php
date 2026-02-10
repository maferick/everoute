<?php

declare(strict_types=1);

namespace Everoute\Routing;

use Everoute\Security\Logger;
use Everoute\Universe\StargateRepository;
use Everoute\Universe\SystemRepository;

final class GraphStore
{
    private static bool $loaded = false;
    /** @var array<int, array<string, mixed>> */
    private static array $systems = [];
    /** @var array<string, int> */
    private static array $systemsByName = [];
    private static ?Graph $graph = null;
    /** @var array<int, array<int, array{to: int, is_regional_gate: bool}>> */
    private static array $adjacency = [];
    /** @var array<int, int[]> */
    private static array $gateNeighbors = [];
    /** @var array<int, array<int, array{to: int, is_regional_gate: bool}>> */
    private static array $reverseAdjacency = [];
    /** @var array<int, int> */
    private static array $regionalGateDistance = [];

    public static function load(SystemRepository $systemsRepo, StargateRepository $stargatesRepo, Logger $logger): void
    {
        if (self::$loaded) {
            return;
        }

        self::$systems = [];
        self::$systemsByName = [];
        foreach ($systemsRepo->listForRouting() as $system) {
            $id = (int) $system['id'];
            self::$systems[$id] = $system;
            self::$systemsByName[strtolower((string) $system['name'])] = $id;
        }

        self::$graph = new Graph();
        self::$adjacency = [];
        self::$gateNeighbors = [];
        self::$reverseAdjacency = [];
        self::$regionalGateDistance = [];
        $regionalGateSeeds = [];
        foreach ($stargatesRepo->allEdges() as $edge) {
            $from = (int) $edge['from_system_id'];
            $to = (int) $edge['to_system_id'];
            $isRegional = !empty($edge['is_regional_gate']);
            self::$graph->addEdge($from, $to);
            self::$adjacency[$from][] = ['to' => $to, 'is_regional_gate' => $isRegional];
            self::$gateNeighbors[$from][] = $to;
            self::$reverseAdjacency[$to][] = ['to' => $from, 'is_regional_gate' => $isRegional];
            if ($isRegional) {
                $regionalGateSeeds[$from] = true;
                $regionalGateSeeds[$to] = true;
            }
        }

        if ($regionalGateSeeds !== []) {
            $queue = new \SplQueue();
            foreach (array_keys($regionalGateSeeds) as $systemId) {
                self::$regionalGateDistance[$systemId] = 0;
                $queue->enqueue($systemId);
            }
            while (!$queue->isEmpty()) {
                $current = (int) $queue->dequeue();
                $currentDistance = self::$regionalGateDistance[$current] ?? 0;
                $neighbors = self::$gateNeighbors[$current] ?? [];
                foreach (self::$reverseAdjacency[$current] ?? [] as $edge) {
                    $neighbors[] = (int) ($edge['to'] ?? 0);
                }
                foreach (array_unique($neighbors) as $neighbor) {
                    if ($neighbor === 0) {
                        continue;
                    }
                    if (!isset(self::$regionalGateDistance[$neighbor])) {
                        self::$regionalGateDistance[$neighbor] = $currentDistance + 1;
                        $queue->enqueue($neighbor);
                    }
                }
            }
        }

        foreach (self::$regionalGateDistance as $systemId => $distance) {
            if (isset(self::$systems[$systemId])) {
                self::$systems[$systemId]['regional_gate_distance'] = $distance;
            }
        }

        self::$loaded = true;
        $logger->info('Route graph loaded', ['systems' => count(self::$systems)]);
    }

    public static function refresh(SystemRepository $systemsRepo, StargateRepository $stargatesRepo, Logger $logger): void
    {
        self::$loaded = false;
        self::load($systemsRepo, $stargatesRepo, $logger);
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }

    /** @return array<int, array<string, mixed>> */
    public static function systems(): array
    {
        return self::$systems;
    }

    public static function systemByNameOrId(string $value): ?array
    {
        if (ctype_digit($value)) {
            $id = (int) $value;
            return self::$systems[$id] ?? null;
        }

        $id = self::$systemsByName[strtolower($value)] ?? null;
        if ($id === null) {
            return null;
        }

        return self::$systems[$id] ?? null;
    }

    public static function graph(): Graph
    {
        if (self::$graph === null) {
            throw new \RuntimeException('Graph store has not been loaded.');
        }

        return self::$graph;
    }

    /** @return array<int, array<int, array{to: int, is_regional_gate: bool}>> */
    public static function adjacency(): array
    {
        return self::$adjacency;
    }

    /** @return array<int, int[]> */
    public static function gateNeighbors(): array
    {
        return self::$gateNeighbors;
    }

    /** @return array<int, array<int, array{to: int, is_regional_gate: bool}>> */
    public static function reverseAdjacency(): array
    {
        return self::$reverseAdjacency;
    }

    /** @return array<int, int> */
    public static function regionalGateDistance(): array
    {
        return self::$regionalGateDistance;
    }

    public static function systemCount(): int
    {
        return count(self::$systems);
    }
}
