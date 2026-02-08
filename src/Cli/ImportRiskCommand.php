<?php

declare(strict_types=1);

namespace Everoute\Cli;

use PDO;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ImportRiskCommand extends Command
{
    use DbAware;

    protected static $defaultName = 'import:risk';

    protected function configure(): void
    {
        $this
            ->setDescription('Import risk data from JSON or CSV')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to risk file')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'json|csv', 'json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = (string) $input->getOption('file');
        $format = (string) $input->getOption('format');
        if ($file === '' || !file_exists($file)) {
            $output->writeln('<error>File not found.</error>');
            return Command::FAILURE;
        }

        $pdo = $this->connection()->pdo();
        $pdo->beginTransaction();

        if ($format === 'json') {
            $payload = json_decode((string) file_get_contents($file), true);
            if (!is_array($payload)) {
                $output->writeln('<error>Invalid JSON.</error>');
                return Command::FAILURE;
            }
            $this->importRisk($pdo, $payload);
        } else {
            $output->writeln('<error>CSV format not yet supported; use JSON.</error>');
            return Command::FAILURE;
        }

        $pdo->commit();
        $output->writeln('<info>Risk import complete.</info>');
        return Command::SUCCESS;
    }

    private function importRisk(PDO $pdo, array $rows): void
    {
        $stmt = $pdo->prepare('INSERT INTO system_risk (system_id, kills_last_1h, kills_last_24h, pod_kills_last_1h, pod_kills_last_24h, last_updated_at) VALUES (:system_id, :k1h, :k24h, :p1h, :p24h, :updated) ON DUPLICATE KEY UPDATE kills_last_1h=VALUES(kills_last_1h), kills_last_24h=VALUES(kills_last_24h), pod_kills_last_1h=VALUES(pod_kills_last_1h), pod_kills_last_24h=VALUES(pod_kills_last_24h), last_updated_at=VALUES(last_updated_at)');
        $now = gmdate('Y-m-d H:i:s');
        foreach ($rows as $row) {
            $stmt->execute([
                'system_id' => $row['system_id'],
                'k1h' => $row['kills_last_1h'] ?? 0,
                'k24h' => $row['kills_last_24h'] ?? 0,
                'p1h' => $row['pod_kills_last_1h'] ?? 0,
                'p24h' => $row['pod_kills_last_24h'] ?? 0,
                'updated' => $row['last_updated_at'] ?? $now,
            ]);
        }
    }
}
