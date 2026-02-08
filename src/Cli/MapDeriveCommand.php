<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class MapDeriveCommand extends Command
{
    use DbAware;

    protected static $defaultName = 'map:derive';

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Compute derived map data after SDE import');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
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

        $output->writeln('<info>Deriving regional gates...</info>');
        $pdo->exec(
            'UPDATE stargates s
            JOIN systems a ON s.from_system_id = a.id
            JOIN systems b ON s.to_system_id = b.id
            SET s.is_regional_gate = CASE
                WHEN a.region_id IS NOT NULL AND b.region_id IS NOT NULL AND a.region_id <> b.region_id THEN 1
                ELSE 0
            END'
        );

        $output->writeln('<info>Map derived data updated.</info>');
        return Command::SUCCESS;
    }
}
