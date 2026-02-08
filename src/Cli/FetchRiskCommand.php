<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Everoute\Risk\ZkillFetcher;
use Everoute\Universe\SystemRepository;
use PDO;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class FetchRiskCommand extends Command
{
    use DbAware;

    protected static $defaultName = 'risk:fetch';

    protected function configure(): void
    {
        $this
            ->setDescription('Fetch risk data from zKillboard (optional)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit number of systems', 200);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int) $input->getOption('limit');
        $connection = $this->connection();
        $systemsRepo = new SystemRepository($connection);
        $systems = array_slice($systemsRepo->listAll(), 0, $limit);
        $systemIds = array_map(static fn ($system) => (int) $system['id'], $systems);

        $fetcher = new ZkillFetcher();
        $data = $fetcher->fetch($systemIds);

        $pdo = $connection->pdo();
        $stmt = $pdo->prepare('INSERT INTO system_risk (system_id, kills_last_1h, kills_last_24h, pod_kills_last_1h, pod_kills_last_24h, last_updated_at) VALUES (:system_id, :k1h, :k24h, :p1h, :p24h, :updated) ON DUPLICATE KEY UPDATE kills_last_1h=VALUES(kills_last_1h), kills_last_24h=VALUES(kills_last_24h), pod_kills_last_1h=VALUES(pod_kills_last_1h), pod_kills_last_24h=VALUES(pod_kills_last_24h), last_updated_at=VALUES(last_updated_at)');

        foreach ($data as $systemId => $row) {
            $stmt->execute([
                'system_id' => $systemId,
                'k1h' => $row['kills_last_1h'],
                'k24h' => $row['kills_last_24h'],
                'p1h' => $row['pod_kills_last_1h'],
                'p24h' => $row['pod_kills_last_24h'],
                'updated' => $row['last_updated_at'],
            ]);
        }

        $output->writeln('<info>Risk data fetched and stored.</info>');
        return Command::SUCCESS;
    }
}
