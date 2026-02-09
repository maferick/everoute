<?php

declare(strict_types=1);

namespace Everoute\Cli;

use DateTimeImmutable;
use DateTimeZone;
use Everoute\Config\Env;
use Everoute\Risk\EsiSystemKillsClient;
use Everoute\Risk\RiskMetaRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RiskRefreshCommand extends Command
{
    use DbAware;

    protected static $defaultName = 'risk:refresh';

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Refresh risk data from CCP ESI system kills')
            ->addOption('provider', null, InputOption::VALUE_REQUIRED, 'Risk provider', Env::get('RISK_PROVIDER', 'esi_system_kills'));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $provider = (string) $input->getOption('provider');
        if ($provider === '') {
            $provider = Env::get('RISK_PROVIDER', 'esi_system_kills') ?? 'esi_system_kills';
        }
        if ($provider !== 'esi_system_kills') {
            $output->writeln('<error>Unsupported provider. Use --provider=esi_system_kills.</error>');
            return Command::FAILURE;
        }

        $connection = $this->connection();
        $metaRepo = new RiskMetaRepository($connection);
        $meta = $metaRepo->getMeta($provider);
        $etag = $meta['etag'] ?? null;
        $lastModified = isset($meta['last_modified']) && $meta['last_modified']
            ? new DateTimeImmutable((string) $meta['last_modified'], new DateTimeZone('UTC'))
            : null;
        $updatedAt = isset($meta['updated_at']) && $meta['updated_at']
            ? new DateTimeImmutable((string) $meta['updated_at'], new DateTimeZone('UTC'))
            : null;

        $client = new EsiSystemKillsClient();
        $start = microtime(true);
        $response = $client->fetchSystemKills($etag, $lastModified);
        $durationMs = (int) ((microtime(true) - $start) * 1000);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $status = $response['status'];
        $systemsUpdated = 0;
        $etag = $response['etag'] ?? $etag;
        $responseLastModified = $response['last_modified'] ?? $lastModified;

        if ($status === 304) {
            $metaRepo->upsertMeta($provider, $etag, $responseLastModified, $now, $updatedAt);
        } elseif ($status === 200 && is_array($response['data'])) {
            $riskUpdatedAt = $responseLastModified ?? $now;
            $updatedAt = $riskUpdatedAt;
            $pdo = $connection->pdo();
            $pdo->beginTransaction();
            $resetStmt = $pdo->prepare(
                'UPDATE system_risk SET ship_kills_1h = 0, pod_kills_1h = 0, npc_kills_1h = 0, updated_at = :updated_at, risk_updated_at = :risk_updated_at, last_updated_at = :last_updated_at'
            );
            $resetStmt->execute([
                'updated_at' => $now->format('Y-m-d H:i:s'),
                'risk_updated_at' => $riskUpdatedAt->format('Y-m-d H:i:s'),
                'last_updated_at' => $riskUpdatedAt->format('Y-m-d H:i:s'),
            ]);

            $stmt = $pdo->prepare(
                'INSERT INTO system_risk (system_id, ship_kills_1h, pod_kills_1h, npc_kills_1h, updated_at, risk_updated_at, last_updated_at)
                VALUES (:system_id, :ship, :pod, :npc, :updated_at, :risk_updated_at, :last_updated_at)
                ON DUPLICATE KEY UPDATE ship_kills_1h=VALUES(ship_kills_1h), pod_kills_1h=VALUES(pod_kills_1h), npc_kills_1h=VALUES(npc_kills_1h), updated_at=VALUES(updated_at), risk_updated_at=VALUES(risk_updated_at), last_updated_at=VALUES(last_updated_at)'
            );

            foreach ($response['data'] as $row) {
                $systemId = (int) ($row['system_id'] ?? 0);
                if ($systemId <= 0) {
                    continue;
                }
                $stmt->execute([
                    'system_id' => $systemId,
                    'ship' => (int) ($row['ship_kills'] ?? 0),
                    'pod' => (int) ($row['pod_kills'] ?? 0),
                    'npc' => (int) ($row['npc_kills'] ?? 0),
                    'updated_at' => $now->format('Y-m-d H:i:s'),
                    'risk_updated_at' => $riskUpdatedAt->format('Y-m-d H:i:s'),
                    'last_updated_at' => $riskUpdatedAt->format('Y-m-d H:i:s'),
                ]);
                $systemsUpdated++;
            }
            $pdo->commit();

            $metaRepo->upsertMeta($provider, $etag, $responseLastModified ?? $riskUpdatedAt, $now, $updatedAt);
        } else {
            $output->writeln('<error>ESI system kills request failed.</error>');
            return Command::FAILURE;
        }

        $output->writeln(json_encode([
            'status' => $status,
            'systems_updated' => $systemsUpdated,
            'duration_ms' => $durationMs,
            'etag' => $etag,
            'last_modified' => $responseLastModified?->format('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_SLASHES));

        return Command::SUCCESS;
    }
}
