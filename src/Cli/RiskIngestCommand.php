<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Everoute\Cache\RedisCache;
use Everoute\Config\Env;
use Everoute\Risk\RiskAggregator;
use Everoute\Risk\ZkillRedisQClient;
use Everoute\Security\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RiskIngestCommand extends Command
{
    use DbAware;

    protected static $defaultName = 'risk:ingest';

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Ingest zKillboard RedisQ killmails and update risk aggregates')
            ->addOption('seconds', null, InputOption::VALUE_REQUIRED, 'Maximum runtime in seconds', '55');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $queueId = Env::get('RISK_ZKILL_QUEUE_ID');
        if ($queueId === null || $queueId === '') {
            $output->writeln('<error>RISK_ZKILL_QUEUE_ID is required.</error>');
            return Command::FAILURE;
        }

        $ttw = Env::int('RISK_ZKILL_TTW', 5);
        $ttw = max(1, min(10, $ttw));

        $logger = new Logger();
        $riskCache = RedisCache::fromEnv();
        $cacheTtl = Env::int('RISK_CACHE_TTL_SECONDS', 60);
        $connection = $this->connection();
        $aggregator = new RiskAggregator($connection, $riskCache, $cacheTtl);
        $client = new ZkillRedisQClient('Everoute/1.0 (zkillredisq)');

        $pdo = $connection->pdo();
        $insert = $pdo->prepare(
            'INSERT IGNORE INTO kill_events (killmail_id, system_id, happened_at, victim_ship_type_id, is_pod_kill)
            VALUES (:killmail_id, :system_id, :happened_at, :victim_ship_type_id, :is_pod_kill)'
        );

        $minInterval = 0.6;
        $lastRequest = 0.0;
        $backoff = 0;
        $processed = 0;
        $lastKillTime = null;
        $lastPollAt = null;
        $startedAt = microtime(true);
        $maxSeconds = max(1, (int) $input->getOption('seconds'));

        $logger->info('risk_ingest_started', [
            'queue_id' => $queueId,
            'ttw' => $ttw,
            'provider' => Env::get('RISK_PROVIDER', 'manual'),
        ]);

        while (true) {
            if ((microtime(true) - $startedAt) >= $maxSeconds) {
                $output->writeln(sprintf('<info>Ingest complete. Processed %d killmails.</info>', $processed));
                return Command::SUCCESS;
            }
            if ($backoff > 0) {
                $logger->warning('risk_ingest_backoff', ['seconds' => $backoff]);
                sleep($backoff);
            }

            $now = microtime(true);
            $elapsed = $now - $lastRequest;
            if ($elapsed < $minInterval) {
                usleep((int) (($minInterval - $elapsed) * 1_000_000));
            }
            $lastRequest = microtime(true);

            $response = $client->poll($queueId, $ttw);
            $status = $response['status'];

            if ($riskCache) {
                $riskCache->set('risk:ingest:last_seen', gmdate('c'), 180);
            }

            if ($status === 429) {
                $backoff = min($backoff > 0 ? $backoff * 2 : 5, 120);
                $logger->warning('risk_ingest_rate_limited', ['status' => $status, 'backoff' => $backoff]);
                continue;
            }

            if ($status === 0) {
                $backoff = min($backoff > 0 ? $backoff * 2 : 5, 120);
                $logger->warning('risk_ingest_network_error', ['status' => $status, 'backoff' => $backoff]);
                continue;
            }

            $backoff = 0;
            $lastPollAt = gmdate('c');
            $body = $response['body'];
            if (!is_array($body)) {
                $logger->warning('risk_ingest_invalid_response', ['status' => $status, 'last_successful_poll' => $lastPollAt]);
                continue;
            }

            $package = $body['package'] ?? null;
            if ($package === null) {
                $logger->info('risk_ingest_no_package', ['status' => $status, 'last_successful_poll' => $lastPollAt]);
                continue;
            }

            $killmail = $package['killmail'] ?? null;
            if (!is_array($killmail)) {
                $logger->warning('risk_ingest_missing_killmail');
                continue;
            }

            $killmailId = $killmail['killmail_id'] ?? null;
            $systemId = $killmail['solar_system_id'] ?? null;
            if ($killmailId === null || $systemId === null) {
                $logger->warning('risk_ingest_missing_fields', ['killmail_id' => $killmailId, 'system_id' => $systemId]);
                continue;
            }

            $killTime = $killmail['killmail_time'] ?? gmdate('Y-m-d H:i:s');
            $victim = $killmail['victim'] ?? [];
            $victimShipTypeId = isset($victim['ship_type_id']) ? (int) $victim['ship_type_id'] : null;
            $isPodKill = (int) (($victimShipTypeId ?? 0) === 670);

            $insert->execute([
                'killmail_id' => $killmailId,
                'system_id' => $systemId,
                'happened_at' => $killTime,
                'victim_ship_type_id' => $victimShipTypeId,
                'is_pod_kill' => $isPodKill,
            ]);

            if ($insert->rowCount() === 0) {
                continue;
            }

            $processed++;
            $lastKillTime = $killTime;
            $aggregator->updateSystem((int) $systemId);

            $logger->info('risk_ingest_processed', [
                'count' => $processed,
                'killmail_id' => $killmailId,
                'system_id' => $systemId,
                'last_kill_time' => $lastKillTime,
                'last_successful_poll' => $lastPollAt,
            ]);
        }
    }
}
