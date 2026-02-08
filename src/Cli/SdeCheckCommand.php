<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Everoute\Sde\SdeConfig;
use Everoute\Sde\SdeDownloader;
use Everoute\Sde\SdeHttpClient;
use Everoute\Sde\SdeMetaRepository;
use Everoute\Sde\SdeStorage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class SdeCheckCommand extends Command
{
    use DbAware;

    protected static $defaultName = 'sde:check';

    protected function configure(): void
    {
        $this->setName(self::$defaultName)
            ->setDescription('Check CCP SDE build status and whether an update is needed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = SdeConfig::fromEnv();
        $storage = new SdeStorage($config);
        $http = new SdeHttpClient($config);
        $downloader = new SdeDownloader($config, $storage, $http);
        $meta = new SdeMetaRepository($this->connection());

        $latest = $downloader->fetchLatestBuildNumber();
        $current = $meta->currentBuildNumber();

        $output->writeln(sprintf('<info>Latest CCP SDE build: %d</info>', $latest));
        if ($current === null) {
            $output->writeln('<comment>No SDE build installed yet.</comment>');
            $output->writeln('<comment>Update needed: yes</comment>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>Installed SDE build: %d</info>', $current));
        $output->writeln(sprintf('<comment>Update needed: %s</comment>', $latest > $current ? 'yes' : 'no'));

        return Command::SUCCESS;
    }
}
