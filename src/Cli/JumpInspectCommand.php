<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class JumpInspectCommand extends Command
{
    use DbAware;

    protected static $defaultName = 'jump:inspect';

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Inspect jump neighbor counts for a system')
            ->addOption('system-id', null, InputOption::VALUE_REQUIRED, 'System ID to inspect');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $systemId = (int) $input->getOption('system-id');
        if ($systemId <= 0) {
            $output->writeln('<error>Provide a valid --system-id.</error>');
            return Command::FAILURE;
        }

        $pdo = $this->connection()->pdo();
        $rangeColumn = $this->resolveRangeColumn($pdo);
        $stmt = $pdo->prepare(sprintf(
            'SELECT %s AS range_ly, neighbor_count
             FROM jump_neighbors
             WHERE system_id = :system_id
             ORDER BY %s',
            $rangeColumn,
            $rangeColumn
        ));
        $stmt->execute(['system_id' => $systemId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        if ($rows === []) {
            $output->writeln(sprintf('<comment>No jump neighbor rows found for system %d.</comment>', $systemId));
            return Command::SUCCESS;
        }

        foreach ($rows as $row) {
            $output->writeln(sprintf(
                '%d LY: %d neighbors',
                (int) $row['range_ly'],
                (int) $row['neighbor_count']
            ));
        }

        return Command::SUCCESS;
    }

    private function resolveRangeColumn(\PDO $pdo): string
    {
        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->query("PRAGMA table_info('jump_neighbors')");
            $columns = $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN, 1) : [];
        } else {
            $stmt = $pdo->prepare(
                'SELECT column_name FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = :table
                   AND (column_name = :range_ly OR column_name = :range)'
            );
            $stmt->execute([
                'table' => 'jump_neighbors',
                'range_ly' => 'range_ly',
                'range' => 'range',
            ]);
            $columns = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        }

        if (in_array('range_ly', $columns, true)) {
            return 'range_ly';
        }
        if (in_array('range', $columns, true)) {
            return 'range';
        }

        throw new \RuntimeException('Missing range column on jump_neighbors. Expected range_ly or range.');
    }
}
