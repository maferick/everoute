<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Everoute\Cache\RedisCache;
use Everoute\Config\Env;
use Everoute\Risk\RiskRepository;
use Everoute\Routing\JumpFatigueModel;
use Everoute\Routing\JumpRangeCalculator;
use Everoute\Routing\NavigationEngine;
use Everoute\Routing\RouteService;
use Everoute\Routing\ShipRules;
use Everoute\Routing\SystemLookup;
use Everoute\Security\Logger;
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

        $logger = new Logger();
        $systems = new SystemRepository($connection);
        $engine = new NavigationEngine(
            $systems,
            new StargateRepository($connection),
            new JumpNeighborRepository($connection, $logger),
            new RiskRepository($connection, $riskCache, $riskCacheTtl, $heatmapTtl),
            new JumpRangeCalculator(__DIR__ . '/../../config/ships.php', __DIR__ . '/../../config/jump_ranges.php'),
            new JumpFatigueModel(),
            new ShipRules(),
            new SystemLookup($systems),
            $logger
        );
        $service = new RouteService(
            $engine,
            $logger,
            $riskCache,
            $routeCacheTtl
        );
        $service->refresh();
        $output->writeln('<info>Cache warmed.</info>');
        return Command::SUCCESS;
    }
}
