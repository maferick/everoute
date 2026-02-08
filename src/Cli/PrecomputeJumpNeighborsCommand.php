<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Everoute\Routing\JumpMath;
use Everoute\Routing\JumpNeighborGraphBuilder;
use Everoute\Routing\JumpRangeCalculator;
use Everoute\Universe\PrecomputeCheckpointRepository;
use Everoute\Universe\SystemRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PrecomputeJumpNeighborsCommand extends Command
{
    use DbAware;

    protected static $defaultName = 'precompute:jump-neighbors';

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
        $checkpointRepo = new PrecomputeCheckpointRepository($connection);
        $rangeCalculator = new JumpRangeCalculator(__DIR__ . '/../../config/jump_ranges.php');
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

        $this->purgeUnsupportedRanges($output, $pdo, max($ranges));

        foreach ($ranges as $rangeLy) {
            $jobKey = 'jump_neighbors:' . $rangeLy;
            if (!$resume) {
                $output->writeln(sprintf('<comment>Clearing jump_neighbors for %d LY...</comment>', $rangeLy));
                $stmt = $pdo->prepare('DELETE FROM jump_neighbors WHERE range_ly = :range');
                $stmt->execute(['range' => $rangeLy]);
                $checkpointRepo->clear($jobKey);
            }

            $cursor = $resume ? $checkpointRepo->getCursor($jobKey) : null;
            $rangeMeters = $rangeLy * JumpMath::METERS_PER_LY;
            $bucketIndex = $builder->buildSpatialBuckets($systems, $rangeMeters);
            $processed = 0;

            $output->writeln(sprintf('<info>Computing jump neighbors for %d LY...</info>', $rangeLy));

            foreach ($systemIds as $systemId) {
                if ($cursor !== null && $systemId <= $cursor) {
                    continue;
                }

                $system = $systems[$systemId];
                $neighbors = $builder->buildNeighborsForSystem($system, $systems, $bucketIndex, $rangeMeters);
                $neighbors = $this->capNeighbors($neighbors, $neighborCap, $output, $systemId, $rangeLy);
                $payload = gzcompress(json_encode($neighbors, JSON_THROW_ON_ERROR));
                $payloadBytes = is_string($payload) ? strlen($payload) : 0;
                $totalStoredBytes += $payloadBytes;
                if ($storageWarningBytes > 0 && $totalStoredBytes >= $storageWarningBytes) {
                    $output->writeln(sprintf(
                        '<comment>Total neighbor storage crossed %s bytes (current: %s bytes).</comment>',
                        number_format($storageWarningBytes),
                        number_format($totalStoredBytes)
                    ));
                    $storageWarningBytes = 0;
                }

                $stmt = $pdo->prepare(
                    'INSERT INTO jump_neighbors (system_id, range_ly, neighbor_count, neighbor_ids_blob, updated_at)
                    VALUES (:system_id, :range_ly, :neighbor_count, :payload, NOW())
                    ON DUPLICATE KEY UPDATE neighbor_count = VALUES(neighbor_count), neighbor_ids_blob = VALUES(neighbor_ids_blob), updated_at = VALUES(updated_at)'
                );
                $stmt->execute([
                    'system_id' => $systemId,
                    'range_ly' => $rangeLy,
                    'neighbor_count' => count($neighbors),
                    'payload' => $payload,
                ]);

                $processed++;
                $checkpointRepo->updateCursor($jobKey, $systemId);

                if ($processed % 50 === 0 || $processed === $totalSystems) {
                    $this->reportProgress($output, $processed, $totalSystems, $startedAt, (string) $rangeLy);
                }

                if ($sleepSeconds > 0) {
                    usleep((int) ($sleepSeconds * 1_000_000));
                }

                if ($hours > 0 && (microtime(true) - $startedAt) >= ($hours * 3600)) {
                    $output->writeln('<comment>Time limit reached, stopping for resume.</comment>');
                    return Command::SUCCESS;
                }
            }

            $this->reportProgress($output, $processed, $totalSystems, $startedAt, (string) $rangeLy);
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

    private function purgeUnsupportedRanges(OutputInterface $output, \PDO $pdo, int $maxRange): void
    {
        $stmt = $pdo->prepare('DELETE FROM jump_neighbors WHERE range_ly > :max_range');
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
}
