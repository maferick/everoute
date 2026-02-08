<?php

declare(strict_types=1);

namespace Everoute\Cli;

use DateTimeImmutable;
use Everoute\Cache\RedisCache;
use Everoute\Config\Env;
use Everoute\DB\Connection;
use Everoute\Routing\GraphStore;
use Everoute\Security\Logger;
use Everoute\Universe\StargateRepository;
use Everoute\Universe\SystemRepository;
use PDO;
use PDOException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class DoctorCommand extends Command
{
    protected static $defaultName = 'doctor';

    protected function configure(): void
    {
        $this
            ->setName('doctor')
            ->setDescription('Check database connectivity and data freshness')
            ->addOption('db-host', null, InputOption::VALUE_REQUIRED, 'DB host', Env::get('DB_HOST', '127.0.0.1'))
            ->addOption('db-port', null, InputOption::VALUE_REQUIRED, 'DB port', (string) Env::int('DB_PORT', 3306))
            ->addOption('db-name', null, InputOption::VALUE_REQUIRED, 'DB name', Env::get('DB_NAME', 'everoute'))
            ->addOption('db-user', null, InputOption::VALUE_REQUIRED, 'DB user', Env::get('DB_USER', 'everoute_app'))
            ->addOption('db-pass', null, InputOption::VALUE_REQUIRED, 'DB password', Env::get('DB_PASS', ''));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dbPass = (string) $input->getOption('db-pass');
        if ($dbPass === '') {
            $output->writeln('<error>DB_PASS missing. Provide via --db-pass or .env</error>');
            return Command::FAILURE;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            (string) $input->getOption('db-host'),
            (int) $input->getOption('db-port'),
            (string) $input->getOption('db-name')
        );

        try {
            $pdo = new PDO($dsn, (string) $input->getOption('db-user'), $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (PDOException $exception) {
            $output->writeln('<error>Database connection failed: ' . $exception->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $systems = (int) $pdo->query('SELECT COUNT(*) FROM systems')->fetchColumn();
        $chokepoints = (int) $pdo->query('SELECT COUNT(*) FROM chokepoints')->fetchColumn();
        $riskUpdated = $pdo->query('SELECT MAX(last_updated_at) FROM system_risk')->fetchColumn();

        $connection = new Connection($dsn, (string) $input->getOption('db-user'), $dbPass);
        $graphStatus = 'not_loaded';
        try {
            GraphStore::load(new SystemRepository($connection), new StargateRepository($connection), new Logger());
            $graphStatus = GraphStore::isLoaded() ? 'loaded' : 'not_loaded';
        } catch (\Throwable $exception) {
            $graphStatus = 'error';
        }

        $redis = RedisCache::fromEnv();
        $redisStatus = 'disabled';
        $cacheStats = null;
        if ($redis) {
            $redisStatus = $redis->ping() ? 'connected' : 'unreachable';
            $cacheStats = $redis->stats();
        }

        $output->writeln('<info>Database connection OK.</info>');
        $output->writeln(sprintf('<comment>Systems: %d</comment>', $systems));
        $output->writeln(sprintf('<comment>Chokepoints: %d</comment>', $chokepoints));
        $output->writeln(sprintf('<comment>Graph loaded: %s (systems cached: %d)</comment>', $graphStatus, GraphStore::systemCount()));
        $output->writeln(sprintf('<comment>Redis: %s</comment>', $redisStatus));

        if ($cacheStats !== null) {
            $output->writeln(sprintf(
                '<comment>Cache stats: keys=%s hits=%s misses=%s memory=%s</comment>',
                $cacheStats['keys'] ?? 'n/a',
                $cacheStats['keyspace_hits'] ?? 'n/a',
                $cacheStats['keyspace_misses'] ?? 'n/a',
                $cacheStats['used_memory'] ?? 'n/a'
            ));
        }

        if ($riskUpdated) {
            $lastUpdated = new DateTimeImmutable((string) $riskUpdated);
            $ageSeconds = (new DateTimeImmutable())->getTimestamp() - $lastUpdated->getTimestamp();
            $ageHours = $ageSeconds / 3600;
            $output->writeln(sprintf('<comment>Risk data last updated: %s (%.1f hours ago)</comment>', $lastUpdated->format('c'), $ageHours));
        } else {
            $output->writeln('<comment>Risk data last updated: none</comment>');
        }

        return Command::SUCCESS;
    }
}
