<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Everoute\Routing\JumpMath;
use Everoute\Universe\JumpNeighborCodec;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class JumpConstellationBuildCommand extends Command
{
    use DbAware;

    protected static $defaultName = 'jump:constellation:build';

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Build range-bucketed jump constellation portals, edges, and midpoint candidates.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pdo = $this->connection()->pdo();
        $systems = $pdo->query('SELECT id, constellation_id, x, y, z FROM systems WHERE constellation_id IS NOT NULL')->fetchAll();
        $constellationBySystem = [];
        $coordsBySystem = [];
        foreach ($systems as $row) {
            $systemId = (int) $row['id'];
            $constellationBySystem[$systemId] = (int) $row['constellation_id'];
            $coordsBySystem[$systemId] = [
                'x' => (float) ($row['x'] ?? 0.0),
                'y' => (float) ($row['y'] ?? 0.0),
                'z' => (float) ($row['z'] ?? 0.0),
            ];
        }

        $buckets = $pdo->query('SELECT DISTINCT range_ly FROM jump_neighbors ORDER BY range_ly')->fetchAll(\PDO::FETCH_COLUMN);
        $pdo->beginTransaction();
        $pdo->exec('TRUNCATE TABLE jump_constellation_portals');
        $pdo->exec('TRUNCATE TABLE jump_constellation_edges');
        $pdo->exec('TRUNCATE TABLE jump_midpoint_candidates');

        $portalInsert = $pdo->prepare(
            'INSERT INTO jump_constellation_portals (constellation_id, range_ly, system_id, outbound_constellations_count)
             VALUES (:constellation_id, :range_ly, :system_id, :outbound_constellations_count)'
        );
        $edgeInsert = $pdo->prepare(
            'INSERT INTO jump_constellation_edges (range_ly, from_constellation_id, to_constellation_id, example_from_system_id, example_to_system_id, min_hop_ly)
             VALUES (:range_ly, :from_constellation_id, :to_constellation_id, :example_from_system_id, :example_to_system_id, :min_hop_ly)'
        );
        $midpointInsert = $pdo->prepare(
            'INSERT INTO jump_midpoint_candidates (constellation_id, range_ly, system_id, score)
             VALUES (:constellation_id, :range_ly, :system_id, :score)'
        );

        $neighborStmt = $pdo->prepare(
            'SELECT system_id, neighbor_count, neighbor_ids_blob, encoding_version
             FROM jump_neighbors
             WHERE range_ly = :range_ly'
        );

        foreach ($buckets as $rawBucket) {
            $rangeBucket = (int) $rawBucket;
            $neighborStmt->execute(['range_ly' => $rangeBucket]);

            $portalTargets = [];
            $edgeStats = [];
            while ($row = $neighborStmt->fetch(\PDO::FETCH_ASSOC)) {
                $fromSystemId = (int) ($row['system_id'] ?? 0);
                $fromConstellationId = $constellationBySystem[$fromSystemId] ?? 0;
                if ($fromConstellationId === 0) {
                    continue;
                }
                $neighborIds = JumpNeighborCodec::decodeV1(
                    (string) ($row['neighbor_ids_blob'] ?? ''),
                    (int) ($row['neighbor_count'] ?? 0)
                );
                foreach ($neighborIds as $toSystemId) {
                    $toConstellationId = $constellationBySystem[(int) $toSystemId] ?? 0;
                    if ($toConstellationId === 0 || $toConstellationId === $fromConstellationId) {
                        continue;
                    }

                    $portalTargets[$fromConstellationId][$fromSystemId][$toConstellationId] = true;
                    $distanceLy = JumpMath::distanceLy($coordsBySystem[$fromSystemId] ?? [], $coordsBySystem[(int) $toSystemId] ?? []);
                    $key = $fromConstellationId . ':' . $toConstellationId;
                    if (!isset($edgeStats[$key]) || $distanceLy < $edgeStats[$key]['min_hop_ly']) {
                        $edgeStats[$key] = [
                            'from_constellation_id' => $fromConstellationId,
                            'to_constellation_id' => $toConstellationId,
                            'example_from_system_id' => $fromSystemId,
                            'example_to_system_id' => (int) $toSystemId,
                            'min_hop_ly' => $distanceLy,
                        ];
                    }
                }
            }

            foreach ($portalTargets as $constellationId => $systemsMap) {
                foreach ($systemsMap as $systemId => $targets) {
                    $outboundCount = count($targets);
                    $portalInsert->execute([
                        'constellation_id' => $constellationId,
                        'range_ly' => $rangeBucket,
                        'system_id' => $systemId,
                        'outbound_constellations_count' => $outboundCount,
                    ]);
                    $midpointInsert->execute([
                        'constellation_id' => $constellationId,
                        'range_ly' => $rangeBucket,
                        'system_id' => $systemId,
                        'score' => (float) $outboundCount,
                    ]);
                }
            }

            foreach ($edgeStats as $edge) {
                $edgeInsert->execute([
                    'range_ly' => $rangeBucket,
                    'from_constellation_id' => $edge['from_constellation_id'],
                    'to_constellation_id' => $edge['to_constellation_id'],
                    'example_from_system_id' => $edge['example_from_system_id'],
                    'example_to_system_id' => $edge['example_to_system_id'],
                    'min_hop_ly' => round((float) $edge['min_hop_ly'], 3),
                ]);
            }
        }

        $pdo->commit();
        $output->writeln('<info>Jump constellation graph build complete.</info>');

        return Command::SUCCESS;
    }
}

