<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Everoute\DB\Connection;
use Everoute\Risk\RiskRepository;
use Everoute\Routing\RouteService;
use Everoute\Routing\WeightCalculator;
use Everoute\Security\Logger;
use Everoute\Universe\StargateRepository;
use Everoute\Universe\StationRepository;
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
        $service = new RouteService(
            new SystemRepository($connection),
            new StargateRepository($connection),
            new StationRepository($connection),
            new RiskRepository($connection),
            new WeightCalculator(),
            new Logger()
        );
        $service->refresh();
        $output->writeln('<info>Cache warmed.</info>');
        return Command::SUCCESS;
    }
}
