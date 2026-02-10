<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PrecomputeSystemFactsCommand extends Command
{
    use DbAware;

    protected static $defaultName = 'precompute:system-facts';

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Precompute static system facts (NPC stations, regional gates, universe classification)')
            ->addOption('include-wormholes', null, InputOption::VALUE_NONE, 'Include wormhole and non-normal-universe systems where supported');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $includeWormholes = (bool) $input->getOption('include-wormholes');

        $connection = $this->connection();
        $pdo = $connection->pdo();

        $output->writeln('<info>Updating NPC station counts...</info>');
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

        $output->writeln('<info>Updating regional gate flags...</info>');
        $pdo->exec(
            'UPDATE stargates s
            JOIN systems a ON s.from_system_id = a.id
            JOIN systems b ON s.to_system_id = b.id
            SET s.is_regional_gate = CASE
                WHEN a.region_id IS NOT NULL AND b.region_id IS NOT NULL AND a.region_id <> b.region_id THEN 1
                ELSE 0
            END'
        );


        $output->writeln('<info>Updating system classification flags...</info>');
        $pdo->exec(
            'UPDATE systems
            SET is_wormhole = CASE
                WHEN region_id BETWEEN 11000000 AND 11999999 THEN 1
                ELSE 0
            END,
            is_normal_universe = CASE
                WHEN region_id BETWEEN 10000001 AND 10001000 THEN 1
                ELSE 0
            END'
        );

        if ($includeWormholes) {
            $output->writeln('<comment>--include-wormholes specified: downstream precompute commands may include classified-out systems when supported.</comment>');
        }

        $output->writeln('<info>System facts updated.</info>');
        return Command::SUCCESS;
    }
}
