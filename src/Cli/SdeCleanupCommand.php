<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Everoute\Sde\SdeConfig;
use Everoute\Sde\SdeStorage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class SdeCleanupCommand extends Command
{
    protected static $defaultName = 'sde:cleanup';

    protected function configure(): void
    {
        $this->setName(self::$defaultName)
            ->setDescription('Remove SDE downloads/extracts older than N days')
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Remove files older than N days', '14');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $days = max(1, (int) $input->getOption('days'));
        $config = SdeConfig::fromEnv();
        $storage = new SdeStorage($config);

        $result = $storage->cleanup($days);
        $output->writeln(sprintf('<info>Removed %d files and %d directories older than %d days.</info>', $result['files'], $result['dirs'], $days));

        return Command::SUCCESS;
    }
}
