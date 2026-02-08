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

final class SdeInstallCommand extends Command
{
    use DbAware;

    protected static $defaultName = 'sde:install';

    protected function configure(): void
    {
        $this->setName(self::$defaultName)
            ->setDescription('Install CCP SDE data (systems, stargates, NPC stations) from official endpoints');
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
        $output->writeln(sprintf('<info>Latest CCP SDE build: %d</info>', $latest));

        $zipPath = $downloader->downloadZip($latest);
        $output->writeln(sprintf('<info>Downloaded zip: %s</info>', $zipPath));

        $paths = $downloader->extractRequiredFiles($zipPath, $latest);
        $output->writeln('<info>Extracted required JSONL files.</info>');

        $importer->import($paths, $latest);
        $meta->recordInstall($latest, $config->variant, $downloader->buildUrl($latest), 'full import');

        $output->writeln('<info>SDE import complete.</info>');
        $output->writeln('<comment>Run: php bin/console seed:chokepoints (recommended after import).</comment>');

        return Command::SUCCESS;
    }
}
