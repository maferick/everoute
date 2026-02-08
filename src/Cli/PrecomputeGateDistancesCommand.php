<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Everoute\Universe\PrecomputeCheckpointRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PrecomputeGateDistancesCommand extends Command
{
    use DbAware;

    protected static $defaultName = 'precompute:gate-distances';

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Precompute minimum gate hop distances between systems')
            ->addOption('hours', null, InputOption::VALUE_REQUIRED, 'Stop after N hours (0 = no limit)', '1')
            ->addOption('max-hops', null, InputOption::VALUE_REQUIRED, 'Maximum hops to store per source', '20')
            ->addOption('resume', null, InputOption::VALUE_NONE, 'Resume from last checkpoint')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Sleep seconds between sources', '0')
            ->addOption('source-ids', null, InputOption::VALUE_REQUIRED, 'Comma-separated list of source system IDs to process');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hours = (float) $input->getOption('hours');
        $maxHops = (int) $input->getOption('max-hops');
        $resume = (bool) $input->getOption('resume');
        $sleepSeconds = (float) $input->getOption('sleep');
        $sourceIds = $this->parseIdList((string) $input->getOption('source-ids'));

        $connection = $this->connection();
        $pdo = $connection->pdo();
        $checkpointRepo = new PrecomputeCheckpointRepository($connection);
        $jobKey = 'gate_distances';

        if (!$resume) {
            if ($sourceIds === []) {
                $output->writeln('<comment>Clearing gate_distances...</comment>');
                $pdo->exec('TRUNCATE TABLE gate_distances');
            } else {
                $output->writeln('<comment>Clearing selected gate_distances sources...</comment>');
                $this->deleteSources($pdo, $sourceIds);
            }
            $checkpointRepo->clear($jobKey);
        }

        $cursor = $resume ? $checkpointRepo->getCursor($jobKey) : null;
        $systems = $sourceIds !== [] ? $sourceIds : $this->fetchSystemIds($pdo);
        sort($systems);
        if ($cursor !== null) {
            $systems = array_values(array_filter($systems, static fn (int $id): bool => $id > $cursor));
        }

        if ($systems === []) {
            $output->writeln('<info>No systems to process.</info>');
            return Command::SUCCESS;
        }

        $adjacency = $this->loadAdjacency($pdo);
        $total = count($systems);
        $processed = 0;
        $startedAt = microtime(true);

        $output->writeln(sprintf(
            '<info>Processing %d sources (max hops %d)...</info>',
            $total,
            $maxHops
        ));

        foreach ($systems as $systemId) {
            $distances = $this->bfsDistances($systemId, $adjacency, $maxHops);

            $pdo->beginTransaction();
            $deleteStmt = $pdo->prepare('DELETE FROM gate_distances WHERE from_system_id = :from');
            $deleteStmt->execute(['from' => $systemId]);
            $this->insertDistances($pdo, $systemId, $distances);
            $pdo->commit();

            $processed++;
            $checkpointRepo->updateCursor($jobKey, $systemId);

            if ($processed % 25 === 0 || $processed === $total) {
                $this->reportProgress($output, $processed, $total, $startedAt);
            }

            if ($sleepSeconds > 0) {
                usleep((int) ($sleepSeconds * 1_000_000));
            }

            if ($hours > 0 && (microtime(true) - $startedAt) >= ($hours * 3600)) {
                $output->writeln('<comment>Time limit reached, stopping for resume.</comment>');
                break;
            }
        }

        $this->reportProgress($output, $processed, $total, $startedAt);
        $output->writeln('<info>Gate distance precompute complete.</info>');

        return Command::SUCCESS;
    }

    /** @return int[] */
    private function parseIdList(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }
        $parts = array_filter(array_map('trim', explode(',', $value)), static fn (string $part): bool => $part !== '');
        $ids = [];
        foreach ($parts as $part) {
            if (ctype_digit($part)) {
                $ids[] = (int) $part;
            }
        }
        return array_values(array_unique($ids));
    }

    /** @return int[] */
    private function fetchSystemIds(\PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT id FROM systems ORDER BY id');
        return array_map(static fn (array $row): int => (int) $row['id'], $stmt->fetchAll());
    }

    private function deleteSources(\PDO $pdo, array $sourceIds): void
    {
        $placeholders = implode(',', array_fill(0, count($sourceIds), '?'));
        $stmt = $pdo->prepare("DELETE FROM gate_distances WHERE from_system_id IN ($placeholders)");
        $stmt->execute(array_values($sourceIds));
    }

    /** @return array<int, int[]> */
    private function loadAdjacency(\PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT from_system_id, to_system_id FROM stargates');
        $adjacency = [];
        foreach ($stmt->fetchAll() as $row) {
            $adjacency[(int) $row['from_system_id']][] = (int) $row['to_system_id'];
        }
        return $adjacency;
    }

    /** @return array<int, int> */
    private function bfsDistances(int $startId, array $adjacency, int $maxHops): array
    {
        $distances = [$startId => 0];
        $queue = new \SplQueue();
        $queue->enqueue($startId);

        while (!$queue->isEmpty()) {
            $current = $queue->dequeue();
            $depth = $distances[$current] ?? 0;
            if ($depth >= $maxHops) {
                continue;
            }
            foreach ($adjacency[$current] ?? [] as $neighbor) {
                if (!isset($distances[$neighbor])) {
                    $distances[$neighbor] = $depth + 1;
                    $queue->enqueue($neighbor);
                }
            }
        }

        unset($distances[$startId]);
        return $distances;
    }

    private function insertDistances(\PDO $pdo, int $fromId, array $distances): void
    {
        if ($distances === []) {
            return;
        }

        $batchSize = 500;
        $rows = [];
        foreach ($distances as $toId => $hops) {
            $rows[] = [(int) $toId, (int) $hops];
            if (count($rows) >= $batchSize) {
                $this->insertDistanceBatch($pdo, $fromId, $rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            $this->insertDistanceBatch($pdo, $fromId, $rows);
        }
    }

    private function insertDistanceBatch(\PDO $pdo, int $fromId, array $rows): void
    {
        $placeholders = implode(',', array_fill(0, count($rows), '(?, ?, ?)'));
        $sql = 'INSERT INTO gate_distances (from_system_id, to_system_id, hops) VALUES ' . $placeholders;
        $params = [];
        foreach ($rows as [$toId, $hops]) {
            $params[] = $fromId;
            $params[] = $toId;
            $params[] = $hops;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    private function reportProgress(OutputInterface $output, int $processed, int $total, float $startedAt): void
    {
        $elapsed = microtime(true) - $startedAt;
        $rate = $processed > 0 ? $elapsed / $processed : 0.0;
        $remaining = $processed > 0 ? max(0.0, ($total - $processed) * $rate) : 0.0;
        $output->writeln(sprintf(
            '<comment>Progress: %d/%d (%.1f%%), elapsed %s, ETA %s</comment>',
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
}
