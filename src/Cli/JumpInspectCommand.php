<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Everoute\Universe\JumpNeighborRepository;
use Everoute\Universe\SystemRepository;
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
            ->addOption('system-id', null, InputOption::VALUE_REQUIRED, 'System ID to inspect')
            ->addOption('system-name', null, InputOption::VALUE_REQUIRED, 'System name to inspect');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $systemId = (int) $input->getOption('system-id');
        $systemName = (string) $input->getOption('system-name');
        if ($systemId <= 0 && $systemName !== '') {
            $repo = new SystemRepository($this->connection());
            $system = $repo->findByNameOrId($systemName);
            if ($system !== null) {
                $systemId = (int) $system['id'];
            }
        }
        if ($systemId <= 0) {
            $output->writeln('<error>Provide a valid --system-id or --system-name.</error>');
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

        $output->writeln(sprintf('<info>Origin system id: %d</info>', $systemId));
        $countsByRange = [];
        foreach ($rows as $row) {
            $countsByRange[(int) $row['range_ly']] = (int) $row['neighbor_count'];
        }
        for ($range = 1; $range <= 10; $range++) {
            $output->writeln(sprintf(
                '%d LY: %d neighbors',
                $range,
                $countsByRange[$range] ?? 0
            ));
        }

        $stmt = $pdo->prepare(sprintf(
            'SELECT neighbor_count, neighbor_ids_blob FROM jump_neighbors WHERE system_id = :system_id AND %s = :range',
            $rangeColumn
        ));
        $stmt->execute(['system_id' => $systemId, 'range' => 7]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            $decoded = JumpNeighborRepository::decodeNeighborIds($row['neighbor_ids_blob'] ?? null);
            $neighborCount = (int) ($row['neighbor_count'] ?? 0);
            $preview = array_slice($decoded, 0, 10);
            $output->writeln(sprintf(
                '7 LY decoded neighbors: %d (DB count: %d)',
                count($decoded),
                $neighborCount
            ));
            $output->writeln(sprintf(
                '7 LY first 10 neighbor ids: %s',
                $preview === [] ? '(none)' : implode(', ', $preview)
            ));
            if (count($decoded) !== $neighborCount) {
                $output->writeln('<comment>Warning: decoded neighbor count does not match DB neighbor_count.</comment>');
            }
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
