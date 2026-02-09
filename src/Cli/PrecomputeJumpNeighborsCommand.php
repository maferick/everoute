<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Everoute\Routing\JumpMath;
use Everoute\Routing\JumpNeighborGraphBuilder;
use Everoute\Routing\JumpRangeCalculator;
use Everoute\Universe\JumpNeighborCodec;
use Everoute\Universe\PrecomputeCheckpointRepository;
use Everoute\Universe\JumpNeighborValidator;
use Everoute\Universe\SystemRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PrecomputeJumpNeighborsCommand extends Command
{
    use DbAware;

    protected static $defaultName = 'jump:precompute';

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Precompute jump range neighbors for each system')
            ->addOption('hours', null, InputOption::VALUE_REQUIRED, 'Stop after N hours (0 = no limit)', '1')
            ->addOption('ranges', null, InputOption::VALUE_REQUIRED, 'Comma-separated LY ranges to compute (default config)')
            ->addOption('resume', null, InputOption::VALUE_NONE, 'Resume from last checkpoint')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Sleep seconds between systems', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hours = (float) $input->getOption('hours');
        $resume = (bool) $input->getOption('resume');
        $sleepSeconds = (float) $input->getOption('sleep');
        $rangeOption = (string) $input->getOption('ranges');

        $connection = $this->connection();
        $pdo = $connection->pdo();
        if (!$this->tableExists($pdo, 'jump_neighbors')) {
            $output->writeln('<error>Missing jump_neighbors table. Run sql/schema.sql to initialize the database schema.</error>');
            return Command::FAILURE;
        }
        $rangeColumn = $this->ensureRangeColumn($pdo, $output);
        if ($rangeColumn === null) {
            $output->writeln('<error>Missing range column on jump_neighbors. Expected range_ly or range.</error>');
            return Command::FAILURE;
        }
        $this->ensureEncodingVersionColumn($pdo, $output);
        $rangeBucketColumn = $this->resolveRangeBucketColumn($pdo);
        $checkpointRepo = new PrecomputeCheckpointRepository($connection);
        $rangeCalculator = new JumpRangeCalculator(__DIR__ . '/../../config/ships.php', __DIR__ . '/../../config/jump_ranges.php');
        $ranges = $this->parseRanges($rangeOption, $rangeCalculator->rangeBuckets());
        $neighborCap = $rangeCalculator->neighborCapPerSystem();
        $storageWarningBytes = $rangeCalculator->neighborStorageWarningBytes();
        if ($ranges === []) {
            $output->writeln('<error>No jump ranges specified.</error>');
            return Command::FAILURE;
        }

        $systems = $this->loadSystems($connection);
        $systemIds = array_keys($systems);
        $totalSystems = count($systemIds);
        if ($totalSystems === 0) {
            $output->writeln('<comment>No systems available. Did you import the SDE?</comment>');
            return Command::SUCCESS;
        }

        $builder = new JumpNeighborGraphBuilder();
        $startedAt = microtime(true);
        $totalStoredBytes = 0;

        $maxRange = max($ranges);
        $this->purgeUnsupportedRanges($output, $pdo, $maxRange, $rangeColumn);

        $jobKey = 'jump_neighbors:cumulative:' . implode('-', $ranges);
        if (!$resume) {
            $this->clearRanges($output, $pdo, $rangeColumn, $ranges);
            $checkpointRepo->clear($jobKey);
        }

        $cursor = $resume ? $checkpointRepo->getCursor($jobKey) : null;
        $rangeMeters = $maxRange * JumpMath::METERS_PER_LY;
        $bucketIndex = $builder->buildSpatialBuckets($systems, $rangeMeters);
        $processed = 0;

        $output->writeln(sprintf('<info>Computing cumulative jump neighbors up to %d LY...</info>', $maxRange));

        foreach ($systemIds as $systemId) {
            if ($cursor !== null && $systemId <= $cursor) {
                continue;
            }

            $system = $systems[$systemId];
            $neighbors = $builder->buildNeighborsForSystem($system, $systems, $bucketIndex, $rangeMeters);
            $neighborIdsByRange = $this->buildCumulativeNeighbors($neighbors, $ranges, $neighborCap);

            $prevCount = null;
            foreach ($ranges as $rangeLy) {
                $neighborIds = $neighborIdsByRange[$rangeLy];
                $count = count($neighborIds);
                if ($prevCount !== null && $count < $prevCount) {
                    $output->writeln(sprintf(
                        '<error>Neighbor count decreased for system %d at %d LY (prev=%d, count=%d).</error>',
                        $systemId,
                        $rangeLy,
                        $prevCount,
                        $count
                    ));
                    return Command::FAILURE;
                }
                $prevCount = $count;

                $payload = JumpNeighborCodec::encodeV1($neighborIds);
                $payloadBytes = strlen($payload);
                $totalStoredBytes += $payloadBytes;
                if ($storageWarningBytes > 0 && $totalStoredBytes >= $storageWarningBytes) {
                    $output->writeln(sprintf(
                        '<comment>Total neighbor storage crossed %s bytes (current: %s bytes).</comment>',
                        number_format($storageWarningBytes),
                        number_format($totalStoredBytes)
                    ));
                    $storageWarningBytes = 0;
                }

                $insertColumns = ['system_id', $rangeColumn];
                $insertValues = [':system_id', ':range_ly'];
                $updateClauses = [
                    'neighbor_count = VALUES(neighbor_count)',
                    'neighbor_ids_blob = VALUES(neighbor_ids_blob)',
                    'encoding_version = VALUES(encoding_version)',
                    'updated_at = VALUES(updated_at)',
                ];

                if ($rangeBucketColumn !== null) {
                    $insertColumns[] = $rangeBucketColumn;
                    $insertValues[] = ':range_bucket';
                    $updateClauses[] = sprintf('%s = VALUES(%s)', $rangeBucketColumn, $rangeBucketColumn);
                }

                $insertColumns = array_merge($insertColumns, ['neighbor_count', 'neighbor_ids_blob', 'encoding_version', 'updated_at']);
                $insertValues = array_merge($insertValues, [':neighbor_count', ':payload', ':encoding_version', 'NOW()']);

                $stmt = $pdo->prepare(sprintf(
                    'INSERT INTO jump_neighbors (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
                    implode(', ', $insertColumns),
                    implode(', ', $insertValues),
                    implode(', ', $updateClauses)
                ));
                $params = [
                    'system_id' => $systemId,
                    'range_ly' => $rangeLy,
                    'neighbor_count' => $count,
                    'payload' => $payload,
                    'encoding_version' => 1,
                ];
                if ($rangeBucketColumn !== null) {
                    $params['range_bucket'] = $rangeLy;
                }
                $stmt->execute($params);
            }

            $processed++;
            $checkpointRepo->updateCursor($jobKey, $systemId);

            if ($processed % 50 === 0 || $processed === $totalSystems) {
                $this->reportProgress($output, $processed, $totalSystems, $startedAt, sprintf('<=%d', $maxRange));
            }

            if ($sleepSeconds > 0) {
                usleep((int) ($sleepSeconds * 1_000_000));
            }

            if ($hours > 0 && (microtime(true) - $startedAt) >= ($hours * 3600)) {
                $output->writeln('<comment>Time limit reached, stopping for resume.</comment>');
                return Command::SUCCESS;
            }
        }

        $this->reportProgress($output, $processed, $totalSystems, $startedAt, sprintf('<=%d', $maxRange));

        $validator = new JumpNeighborValidator($connection);
        $completeness = $validator->validateCompleteness($ranges);
        if ($completeness['missing_rows_found'] > 0) {
            $output->writeln(sprintf(
                '<error>Missing jump neighbor rows for %d systems.</error>',
                $completeness['missing_rows_found']
            ));
            foreach ($completeness['missing'] as $systemId => $missingCount) {
                $output->writeln(sprintf(
                    '<error>System %d missing %d rows (expected %d).</error>',
                    $systemId,
                    $missingCount,
                    count($ranges)
                ));
            }
            return Command::FAILURE;
        }

        $output->writeln('<info>Jump neighbor precompute complete.</info>');
        return Command::SUCCESS;
    }

    /** @return array<int, array{id:int,x:float,y:float,z:float,security:float,name:string,region_id:int|null,has_npc_station:int,npc_station_count:int,system_size_au:float}> */
    private function loadSystems(\Everoute\DB\Connection $connection): array
    {
        $systems = [];
        $repo = new SystemRepository($connection);
        foreach ($repo->listForRouting() as $system) {
            $systemId = (int) $system['id'];
            $system['id'] = $systemId;
            $systems[$systemId] = $system;
        }
        ksort($systems);
        return $systems;
    }

    /** @return int[] */
    private function parseRanges(string $value, array $defaults): array
    {
        if (trim($value) === '') {
            return array_map(static fn (float $range): int => (int) round($range), $defaults);
        }
        $parts = array_filter(array_map('trim', explode(',', $value)), static fn (string $part): bool => $part !== '');
        $ranges = [];
        foreach ($parts as $part) {
            if (is_numeric($part)) {
                $ranges[] = (int) round((float) $part);
            }
        }
        $ranges = array_values(array_unique($ranges));
        $ranges = array_filter($ranges, static fn (int $range): bool => $range >= 1 && $range <= 10);
        sort($ranges);
        return $ranges;
    }

    /** @param array<int, float> $neighbors */
    private function capNeighbors(array $neighbors, int $cap, OutputInterface $output, int $systemId, int $rangeLy): array
    {
        $count = count($neighbors);
        if ($count <= $cap) {
            return $neighbors;
        }

        $output->writeln(sprintf(
            '<comment>Warning: system %d has %d neighbors at %d LY; capping to %d.</comment>',
            $systemId,
            $count,
            $rangeLy,
            $cap
        ));

        asort($neighbors, SORT_NUMERIC);
        return array_slice($neighbors, 0, $cap, true);
    }

    private function purgeUnsupportedRanges(OutputInterface $output, \PDO $pdo, int $maxRange, string $rangeColumn): void
    {
        $stmt = $pdo->prepare(sprintf('DELETE FROM jump_neighbors WHERE %s > :max_range', $rangeColumn));
        $stmt->execute(['max_range' => $maxRange]);
        $removed = $stmt->rowCount();
        if ($removed > 0) {
            $output->writeln(sprintf(
                '<comment>Removed %d precomputed jump neighbor rows above %d LY.</comment>',
                $removed,
                $maxRange
            ));
        }
    }

    /** @param int[] $ranges */
    private function clearRanges(OutputInterface $output, \PDO $pdo, string $rangeColumn, array $ranges): void
    {
        if ($ranges === []) {
            return;
        }
        $placeholders = implode(', ', array_fill(0, count($ranges), '?'));
        $output->writeln(sprintf(
            '<comment>Clearing jump_neighbors for ranges: %s...</comment>',
            implode(', ', $ranges)
        ));
        $stmt = $pdo->prepare(sprintf('DELETE FROM jump_neighbors WHERE %s IN (%s)', $rangeColumn, $placeholders));
        $stmt->execute(array_values($ranges));
    }

    /**
     * @param array<int, float> $neighbors
     * @param int[] $ranges
     * @return array<int, int[]>
     */
    private function buildCumulativeNeighbors(array $neighbors, array $ranges, int $cap): array
    {
        if ($neighbors === []) {
            $empty = [];
            foreach ($ranges as $rangeLy) {
                $empty[$rangeLy] = [];
            }
            return $empty;
        }

        asort($neighbors, SORT_NUMERIC);
        $neighborIds = array_keys($neighbors);
        $neighborDistances = array_values($neighbors);
        $totalNeighbors = count($neighbors);
        $index = 0;
        $cumulative = [];
        $results = [];

        foreach ($ranges as $rangeLy) {
            while ($index < $totalNeighbors && $neighborDistances[$index] <= $rangeLy) {
                $cumulative[$neighborIds[$index]] = $neighborDistances[$index];
                $index++;
            }

            $selected = $cumulative;
            if (count($selected) > $cap) {
                asort($selected, SORT_NUMERIC);
                $selected = array_slice($selected, 0, $cap, true);
            }

            $ids = array_map('intval', array_keys($selected));
            sort($ids);
            $results[$rangeLy] = $ids;
        }

        return $results;
    }

    private function reportProgress(OutputInterface $output, int $processed, int $total, float $startedAt, string $label): void
    {
        $elapsed = microtime(true) - $startedAt;
        $rate = $processed > 0 ? $elapsed / $processed : 0.0;
        $remaining = $processed > 0 ? max(0.0, ($total - $processed) * $rate) : 0.0;
        $output->writeln(sprintf(
            '<comment>[%s LY] %d/%d (%.1f%%), elapsed %s, ETA %s</comment>',
            $label,
            $processed,
            $total,
            $total > 0 ? ($processed / $total) * 100.0 : 100.0,
            $this->formatDuration($elapsed),
            $this->formatDuration($remaining)
        ));
    }

    private function formatDuration(float $seconds): string
    {
        $seconds = max(0, (int) round($seconds));
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;
        return sprintf('%02dh:%02dm:%02ds', $hours, $minutes, $secs);
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table'
        );
        $stmt->execute(['table' => $table]);
        return (bool) $stmt->fetchColumn();
    }

    private function resolveRangeColumn(\PDO $pdo): ?string
    {
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
        if (in_array('range_ly', $columns, true)) {
            return 'range_ly';
        }
        if (in_array('range', $columns, true)) {
            return 'range';
        }
        return null;
    }

    private function resolveRangeBucketColumn(\PDO $pdo): ?string
    {
        $stmt = $pdo->prepare(
            'SELECT column_name FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table
               AND column_name = :range_bucket'
        );
        $stmt->execute([
            'table' => 'jump_neighbors',
            'range_bucket' => 'range_bucket',
        ]);
        $column = $stmt->fetchColumn();
        return is_string($column) ? $column : null;
    }

    private function ensureRangeColumn(\PDO $pdo, OutputInterface $output): ?string
    {
        $rangeColumn = $this->resolveRangeColumn($pdo);
        if ($rangeColumn !== null) {
            return $rangeColumn;
        }

        $output->writeln('<comment>Missing range column on jump_neighbors. Attempting to add range_ly...</comment>');

        if (!$this->columnExists($pdo, 'jump_neighbors', 'range_ly')) {
            $pdo->exec('ALTER TABLE jump_neighbors ADD COLUMN range_ly SMALLINT UNSIGNED NOT NULL DEFAULT 1 AFTER system_id');
        }

        $this->ensurePrimaryKey($pdo, ['system_id', 'range_ly']);
        $this->ensureIndex($pdo, 'jump_neighbors', 'idx_jump_neighbors_range', 'range_ly');

        return $this->resolveRangeColumn($pdo);
    }

    private function ensureEncodingVersionColumn(\PDO $pdo, OutputInterface $output): void
    {
        if ($this->columnExists($pdo, 'jump_neighbors', 'encoding_version')) {
            return;
        }

        $output->writeln('<comment>Missing encoding_version column on jump_neighbors. Attempting to add it...</comment>');
        $pdo->exec('ALTER TABLE jump_neighbors ADD COLUMN encoding_version TINYINT NOT NULL DEFAULT 1 AFTER neighbor_ids_blob');
    }

    private function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = :table
               AND column_name = :column'
        );
        $stmt->execute(['table' => $table, 'column' => $column]);
        return (bool) $stmt->fetchColumn();
    }

    /** @param string[] $columns */
    private function ensurePrimaryKey(\PDO $pdo, array $columns): void
    {
        $stmt = $pdo->prepare(
            'SELECT column_name FROM information_schema.key_column_usage
             WHERE table_schema = DATABASE()
               AND table_name = :table
               AND constraint_name = :constraint
             ORDER BY ordinal_position'
        );
        $stmt->execute(['table' => 'jump_neighbors', 'constraint' => 'PRIMARY']);
        $existing = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        if ($existing === $columns) {
            return;
        }

        if ($existing !== []) {
            if (in_array('system_id', $existing, true)) {
                $this->ensureIndex($pdo, 'jump_neighbors', 'idx_jump_neighbors_system', 'system_id');
            }
            $pdo->exec('ALTER TABLE jump_neighbors DROP PRIMARY KEY');
        }

        $pdo->exec(sprintf(
            'ALTER TABLE jump_neighbors ADD PRIMARY KEY (%s)',
            implode(', ', $columns)
        ));
    }

    private function ensureIndex(\PDO $pdo, string $table, string $index, string $column): void
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = :table
               AND index_name = :index'
        );
        $stmt->execute(['table' => $table, 'index' => $index]);
        if ($stmt->fetchColumn()) {
            return;
        }

        $pdo->exec(sprintf(
            'ALTER TABLE %s ADD INDEX %s (%s)',
            $table,
            $index,
            $column
        ));
    }
}
