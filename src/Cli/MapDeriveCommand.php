<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class MapDeriveCommand extends Command
{
    use DbAware;

    protected static $defaultName = 'map:derive';

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Compute derived map data after SDE import')
            ->addOption('include-wormholes', null, InputOption::VALUE_NONE, 'Include wormhole and non-normal-universe systems in derived data')
            ->addOption('boundary-radius', null, InputOption::VALUE_REQUIRED, 'Gate hops radius for near-boundary system flags', '1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $includeWormholes = (bool) $input->getOption('include-wormholes');
        $boundaryRadius = max(1, (int) $input->getOption('boundary-radius'));

        $connection = $this->connection();
        $pdo = $connection->pdo();

        $output->writeln('<info>Deriving NPC station counts...</info>');
        $pdo->exec(
            'UPDATE systems s
            LEFT JOIN (
                SELECT system_id, COUNT(*) AS total
                FROM stations
                WHERE is_npc = 1
                GROUP BY system_id
            ) t ON s.id = t.system_id
            SET s.npc_station_count = COALESCE(t.total, 0),
                s.has_npc_station = CASE WHEN t.total IS NULL OR t.total = 0 THEN 0 ELSE 1 END'
        );

        $output->writeln('<info>Deriving boundary gate flags...</info>');
        $pdo->exec(
            'UPDATE stargates s
            JOIN systems a ON s.from_system_id = a.id
            JOIN systems b ON s.to_system_id = b.id
            SET s.is_region_boundary = CASE
                    WHEN a.region_id IS NOT NULL AND b.region_id IS NOT NULL AND a.region_id <> b.region_id THEN 1
                    ELSE 0
                END,
                s.is_constellation_boundary = CASE
                    WHEN a.constellation_id IS NOT NULL AND b.constellation_id IS NOT NULL AND a.constellation_id <> b.constellation_id THEN 1
                    ELSE 0
                END,
                s.is_regional_gate = CASE
                    WHEN a.region_id IS NOT NULL AND b.region_id IS NOT NULL AND a.region_id <> b.region_id THEN 1
                    ELSE 0
                END'
        );

        $output->writeln('<info>Deriving system classification flags...</info>');
        $output->writeln('<info>Updating security class and legality masks...</info>');
        $pdo->exec(
            'UPDATE systems
            SET is_wormhole = CASE
                    WHEN region_id BETWEEN 11000000 AND 11999999 THEN 1
                    ELSE 0
                END,
                is_normal_universe = CASE
                    WHEN region_id BETWEEN 10000001 AND 10001000 THEN 1
                    ELSE 0
                END,
                sec_class = CASE
                    WHEN security_nav >= 0.5 THEN "high"
                    WHEN security_nav >= 0.1 THEN "low"
                    ELSE "null"
                END,
                legal_mask =
                    (CASE WHEN security_nav < 0.5 THEN 1 ELSE 0 END)
                    | (CASE WHEN security_nav < 0.5 THEN 2 ELSE 0 END)
                    | (CASE WHEN security_nav < 0.5 THEN 4 ELSE 0 END)
                    | (CASE WHEN security_nav < 0.5 THEN 8 ELSE 0 END)
                    | (CASE WHEN security_nav < 0.5 THEN 16 ELSE 0 END)
                    | (CASE WHEN security_nav < 0.5 THEN 32 ELSE 0 END)'
        );

        $output->writeln(sprintf('<info>Updating near-boundary system flags (radius=%d)...</info>', $boundaryRadius));
        $pdo->exec('UPDATE systems SET near_region_boundary = 0, near_constellation_boundary = 0');

        $regionSql = '
            UPDATE systems s
            JOIN (
                SELECT DISTINCT gd.from_system_id AS system_id
                FROM gate_distances gd
                JOIN stargates g ON g.from_system_id = gd.to_system_id
                WHERE gd.hops <= ' . $boundaryRadius . ' AND g.is_region_boundary = 1
                UNION
                SELECT DISTINCT gd.to_system_id AS system_id
                FROM gate_distances gd
                JOIN stargates g ON g.from_system_id = gd.to_system_id
                WHERE gd.hops <= ' . $boundaryRadius . ' AND g.is_region_boundary = 1
            ) near ON near.system_id = s.id
            SET s.near_region_boundary = 1';
        $pdo->exec($regionSql);

        $constellationSql = '
            UPDATE systems s
            JOIN (
                SELECT DISTINCT gd.from_system_id AS system_id
                FROM gate_distances gd
                JOIN stargates g ON g.from_system_id = gd.to_system_id
                WHERE gd.hops <= ' . $boundaryRadius . ' AND g.is_constellation_boundary = 1
                UNION
                SELECT DISTINCT gd.to_system_id AS system_id
                FROM gate_distances gd
                JOIN stargates g ON g.from_system_id = gd.to_system_id
                WHERE gd.hops <= ' . $boundaryRadius . ' AND g.is_constellation_boundary = 1
            ) near ON near.system_id = s.id
            SET s.near_constellation_boundary = 1';
        $pdo->exec($constellationSql);

        if ($includeWormholes) {
            $output->writeln('<comment>--include-wormholes specified: downstream routing/precompute commands may include classified-out systems when supported.</comment>');
        }

        $output->writeln('<info>Map derived data updated.</info>');
        return Command::SUCCESS;
    }
}
