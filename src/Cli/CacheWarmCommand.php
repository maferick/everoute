<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Everoute\Cache\RedisCache;
use Everoute\Config\Env;
use Everoute\Risk\RiskRepository;
use Everoute\Routing\JumpPlanner;
use Everoute\Routing\JumpFatigueModel;
use Everoute\Routing\JumpRangeCalculator;
use Everoute\Routing\MovementRules;
use Everoute\Routing\RouteService;
use Everoute\Routing\WeightCalculator;
use Everoute\Security\Logger;
use Everoute\Universe\GateDistanceRepository;
use Everoute\Universe\JumpNeighborRepository;
use Everoute\Universe\StargateRepository;
use Everoute\Universe\SystemRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class CacheWarmCommand extends Command
{
    use DbAware;

    protected static $defaultName = 'cache:warm';

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Warm route data cache');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $connection = $this->connection();
        $riskCache = RedisCache::fromEnv();
        $riskCacheTtl = Env::int('RISK_CACHE_TTL_SECONDS', 60);
        $heatmapTtl = Env::int('RISK_HEATMAP_TTL_SECONDS', 30);
        $routeCacheTtl = Env::int('ROUTE_CACHE_TTL_SECONDS', 600);
        $service = new RouteService(
            new SystemRepository($connection),
            new StargateRepository($connection),
            new RiskRepository($connection, $riskCache, $riskCacheTtl, $heatmapTtl),
            $weightCalculator = new WeightCalculator(),
            $movementRules = new MovementRules(),
            new JumpPlanner(new JumpRangeCalculator(__DIR__ . '/../../config/ships.php', __DIR__ . '/../../config/jump_ranges.php'), $weightCalculator, $movementRules, new JumpFatigueModel(), new Logger(), new JumpNeighborRepository($connection)),
            new Logger(),
            $riskCache,
            $routeCacheTtl,
            $riskCacheTtl,
            new GateDistanceRepository($connection)
        );
        $service->refresh();
        $output->writeln('<info>Cache warmed.</info>');
        return Command::SUCCESS;
    }
}
