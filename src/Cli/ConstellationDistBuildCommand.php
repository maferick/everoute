<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ConstellationDistBuildCommand extends Command
{
    use DbAware;

    protected static $defaultName = 'constellation:dist:build';

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Build per-constellation portal distances via BFS');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pdo = $this->connection()->pdo();

        $systems = $pdo->query('SELECT id, constellation_id FROM systems WHERE constellation_id IS NOT NULL')->fetchAll();
        $systemConstellation = [];
        foreach ($systems as $row) {
            $systemConstellation[(int) $row['id']] = (int) $row['constellation_id'];
        }

        $adj = [];
        foreach ($pdo->query('SELECT from_system_id, to_system_id FROM stargates')->fetchAll() as $row) {
            $from = (int) $row['from_system_id'];
            $to = (int) $row['to_system_id'];
            if (($systemConstellation[$from] ?? null) === null || ($systemConstellation[$to] ?? null) === null) {
                continue;
            }
            if ($systemConstellation[$from] !== $systemConstellation[$to]) {
                continue;
            }
            $adj[$from][] = $to;
        }

        $portals = $pdo->query('SELECT constellation_id, system_id FROM constellation_portals ORDER BY constellation_id, system_id')->fetchAll();

        $pdo->beginTransaction();
        $pdo->exec('TRUNCATE TABLE constellation_dist');
        $insert = $pdo->prepare('INSERT INTO constellation_dist (constellation_id, portal_system_id, system_id, gate_dist) VALUES (:constellation_id,:portal_system_id,:system_id,:gate_dist)');

        foreach ($portals as $portal) {
            $constellationId = (int) $portal['constellation_id'];
            $portalId = (int) $portal['system_id'];
            $dist = $this->bfs($portalId, $adj, $systemConstellation, $constellationId);
            foreach ($dist as $systemId => $hops) {
                $insert->execute([
                    'constellation_id' => $constellationId,
                    'portal_system_id' => $portalId,
                    'system_id' => $systemId,
                    'gate_dist' => $hops,
                ]);
            }
        }

        $pdo->commit();

        $output->writeln('<info>Constellation distance build complete.</info>');
        return Command::SUCCESS;
    }

    /** @return array<int,int> */
    private function bfs(int $start, array $adj, array $systemConstellation, int $constellationId): array
    {
        $dist = [$start => 0];
        $q = new \SplQueue();
        $q->enqueue($start);

        while (!$q->isEmpty()) {
            $current = (int) $q->dequeue();
            $depth = $dist[$current] ?? 0;
            foreach ($adj[$current] ?? [] as $neighbor) {
                $neighbor = (int) $neighbor;
                if (($systemConstellation[$neighbor] ?? null) !== $constellationId) {
                    continue;
                }
                if (isset($dist[$neighbor])) {
                    continue;
                }
                $dist[$neighbor] = $depth + 1;
                $q->enqueue($neighbor);
            }
        }

        return $dist;
    }
}
