<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Everoute\Cache\RedisCache;
use Everoute\Config\Env;
use Everoute\Risk\RiskAggregator;
use Everoute\Security\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RiskPruneCommand extends Command
{
    use DbAware;

    protected static $defaultName = 'risk:prune';

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Prune old kill events and reconcile risk aggregates');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $retentionHours = Env::int('RISK_EVENT_RETENTION_HOURS', 48);
        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify(sprintf('-%d hours', $retentionHours))
            ->format('Y-m-d H:i:s');

        $connection = $this->connection();
        $pdo = $connection->pdo();
        $stmt = $pdo->prepare('DELETE FROM kill_events WHERE happened_at < :cutoff');
        $stmt->execute(['cutoff' => $cutoff]);
        $deleted = $stmt->rowCount();

        $logger = new Logger();
        $logger->info('risk_prune_deleted', ['count' => $deleted, 'cutoff' => $cutoff]);

        $riskCache = RedisCache::fromEnv();
        $cacheTtl = Env::int('RISK_CACHE_TTL_SECONDS', 60);
        $aggregator = new RiskAggregator($connection, $riskCache, $cacheTtl);
        $aggregator->reconcileRecent();

        $output->writeln(sprintf('<info>Deleted %d old kill events.</info>', $deleted));
        return Command::SUCCESS;
    }
}
