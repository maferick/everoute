<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ConstellationDeriveCommand extends Command
{
    use DbAware;

    protected static $defaultName = 'constellation:derive';

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Derive constellation portals and edges from stargates');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pdo = $this->connection()->pdo();

        $pdo->beginTransaction();
        $pdo->exec('TRUNCATE TABLE constellation_portals');
        $pdo->exec('TRUNCATE TABLE constellation_edges');

        $pdo->exec(
            'INSERT INTO constellation_edges (from_constellation_id, to_constellation_id, from_system_id, to_system_id, is_region_boundary)
             SELECT sfrom.constellation_id, sto.constellation_id, g.from_system_id, g.to_system_id, g.is_region_boundary
             FROM stargates g
             JOIN systems sfrom ON sfrom.id = g.from_system_id
             JOIN systems sto ON sto.id = g.to_system_id
             WHERE sfrom.constellation_id IS NOT NULL
               AND sto.constellation_id IS NOT NULL
               AND sfrom.constellation_id <> sto.constellation_id'
        );

        $pdo->exec(
            'INSERT INTO constellation_portals (constellation_id, system_id, has_region_boundary)
             SELECT e.from_constellation_id, e.from_system_id, MAX(e.is_region_boundary)
             FROM constellation_edges e
             GROUP BY e.from_constellation_id, e.from_system_id'
        );

        $pdo->exec(
            'INSERT INTO constellation_portals (constellation_id, system_id, has_region_boundary)
             SELECT e.to_constellation_id, e.to_system_id, MAX(e.is_region_boundary)
             FROM constellation_edges e
             GROUP BY e.to_constellation_id, e.to_system_id
             ON DUPLICATE KEY UPDATE has_region_boundary = GREATEST(constellation_portals.has_region_boundary, VALUES(has_region_boundary))'
        );

        $pdo->commit();

        $output->writeln('<info>Constellation graph derivation complete.</info>');
        return Command::SUCCESS;
    }
}
