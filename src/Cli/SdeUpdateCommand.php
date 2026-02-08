<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Everoute\Sde\SdeConfig;
use Everoute\Sde\SdeDownloader;
use Everoute\Sde\SdeHttpClient;
use Everoute\Sde\SdeImporter;
use Everoute\Sde\SdeMetaRepository;
use Everoute\Sde\SdeStorage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class SdeUpdateCommand extends Command
{
    use DbAware;

    protected static $defaultName = 'sde:update';

    protected function configure(): void
    {
        $this->setName(self::$defaultName)
            ->setDescription('Update CCP SDE data to the latest build (full reimport for v1)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = SdeConfig::fromEnv();
        $storage = new SdeStorage($config);
        $http = new SdeHttpClient($config);
        $downloader = new SdeDownloader($config, $storage, $http);
        $meta = new SdeMetaRepository($this->connection());
        $importer = new SdeImporter($this->connection(), $config);

        $latest = $downloader->fetchLatestBuildNumber();
        $current = $meta->currentBuildNumber();

        if ($current !== null && $current >= $latest) {
            $output->writeln(sprintf('<info>Installed build %d is up to date.</info>', $current));
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>Updating from %s to %d (full reimport).</info>', $current === null ? 'none' : (string) $current, $latest));

        $zipPath = $downloader->downloadZip($latest);
        $paths = $downloader->extractRequiredFiles($zipPath, $latest);

        $importer->import($paths, $latest);
        $meta->recordInstall($latest, $config->variant, $downloader->buildUrl($latest), 'full reimport');

        $output->writeln('<info>SDE update complete.</info>');
        $output->writeln('<comment>Run: php bin/console seed:chokepoints if chokepoints should be refreshed.</comment>');

        return Command::SUCCESS;
    }
}
