#!/usr/bin/env php
<?php

declare(strict_types=1);

use Everoute\Cache\RedisCache;
use Everoute\Config\Env;
use Everoute\DB\Connection;
use Everoute\Risk\RiskRepository;
use Everoute\Routing\JumpFatigueModel;
use Everoute\Routing\JumpRangeCalculator;
use Everoute\Routing\NavigationEngine;
use Everoute\Routing\RouteJobRepository;
use Everoute\Routing\RoutePlanner;
use Everoute\Routing\RouteRequestFactory;
use Everoute\Routing\ShipRules;
use Everoute\Routing\SystemLookup;
use Everoute\Routing\RouteService;
use Everoute\Security\Logger;
use Everoute\Security\Validator;
use Everoute\Universe\JumpNeighborRepository;
use Everoute\Universe\StaticMetaRepository;
use Everoute\Universe\StargateRepository;
use Everoute\Universe\SystemRepository;

require_once __DIR__ . '/../vendor/autoload.php';

Env::load(dirname(__DIR__));
$logger = new Logger();

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    Env::get('DB_HOST', '127.0.0.1'),
    Env::int('DB_PORT', 3306),
    Env::get('DB_NAME', 'everoute')
);
$connection = new Connection($dsn, Env::get('DB_USER', ''), Env::get('DB_PASS', ''));

$riskCache = RedisCache::fromEnv($logger);
$riskRepo = new RiskRepository($connection, $riskCache, Env::int('RISK_CACHE_TTL_SECONDS', 60), Env::int('RISK_HEATMAP_TTL_SECONDS', 30));
$systems = new SystemRepository($connection);
$stargates = new StargateRepository($connection);
$jumpNeighbors = new JumpNeighborRepository($connection, $logger);
$engine = new NavigationEngine(
    $systems,
    $stargates,
    $jumpNeighbors,
    $riskRepo,
    new JumpRangeCalculator(__DIR__ . '/../config/ships.php', __DIR__ . '/../config/jump_ranges.php'),
    new JumpFatigueModel(),
    new ShipRules(),
    new SystemLookup($systems),
    $logger
);
$routeService = new RouteService($engine, $logger, $riskCache, Env::int('ROUTE_CACHE_TTL_SECONDS', 600), new StaticMetaRepository($connection), $riskRepo);
$planner = new RoutePlanner($routeService, $riskRepo);
$requestFactory = new RouteRequestFactory(new Validator());
$jobs = new RouteJobRepository($connection);

$pollMs = Env::int('ROUTE_WORKER_POLL_MS', 500);
$maxSeconds = Env::int('ROUTE_JOB_MAX_SECONDS', 300);

while (true) {
    $lock = RouteJobRepository::uuid();
    $job = $jobs->claimNext($lock);

    if ($job === null) {
        usleep($pollMs * 1000);
        continue;
    }

    $jobId = (string) $job['id'];
    $start = time();

    try {
        $jobs->updateProgress($jobId, ['phase' => 'loading graph', 'pct' => 10, 'message' => 'Loading routing graph']);
        $payload = json_decode((string) $job['request_json'], true, 512, JSON_THROW_ON_ERROR);

        ['request' => $routeRequest] = $requestFactory->fromBody($payload);

        $jobs->updateProgress($jobId, ['phase' => 'gate route', 'pct' => 35, 'message' => 'Computing gate route']);
        $jobs->updateProgress($jobId, ['phase' => 'jump route', 'pct' => 55, 'message' => 'Computing jump route']);
        $jobs->updateProgress($jobId, ['phase' => 'hybrid route', 'pct' => 75, 'message' => 'Computing hybrid route']);

        $result = $planner->compute($routeRequest);

        if ((time() - $start) > $maxSeconds) {
            throw new RuntimeException('route_job_timeout');
        }

        if (isset($result['error'])) {
            $jobs->markFailed($jobId, (string) ($result['reason'] ?? $result['error']));
            continue;
        }

        $jobs->updateProgress($jobId, ['phase' => 'selecting best', 'pct' => 95, 'message' => 'Selecting best route']);
        $jobs->markDone($jobId, $result);
    } catch (Throwable $e) {
        $jobs->markFailed($jobId, $e->getMessage());
    }
}
