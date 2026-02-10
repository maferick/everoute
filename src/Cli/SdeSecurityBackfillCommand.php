<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Everoute\Sde\SdeConfig;
use Everoute\Sde\SdeJsonlReader;
use Everoute\Sde\SdeMetaRepository;
use Everoute\Sde\SdeStorage;
use Everoute\Universe\SecurityStatus;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class SdeSecurityBackfillCommand extends Command
{
    use DbAware;

    protected static $defaultName = 'sde:security-backfill';

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Backfill systems security_true/security_display/sec_class from mapSolarSystems.jsonl with full precision')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to mapSolarSystems.jsonl (defaults to latest extracted SDE file)')
            ->addOption('only-normal-universe', null, InputOption::VALUE_NONE, 'Only update systems where is_normal_universe = 1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = (string) $input->getOption('file');
        if ($file === '') {
            $config = SdeConfig::fromEnv();
            $storage = new SdeStorage($config);
            $meta = new SdeMetaRepository($this->connection());
            $build = $meta->currentBuildNumber();
            if ($build === null) {
                $output->writeln('<error>No installed SDE build metadata found. Provide --file explicitly.</error>');
                return Command::FAILURE;
            }
            $file = $storage->extractDir($build) . '/mapSolarSystems.jsonl';
        }

        if (!is_file($file)) {
            $output->writeln(sprintf('<error>JSONL file not found: %s</error>', $file));
            return Command::FAILURE;
        }

        $pdo = $this->connection()->pdo();
        $onlyNormal = (bool) $input->getOption('only-normal-universe');

        $updateSql = 'UPDATE systems
            SET security_true = :security_true,
                security_display = :security_display,
                security_raw = :security_true,
                security = :security_display,
                security_nav = :security_display,
                sec_class = :sec_class
            WHERE id = :id';
        if ($onlyNormal) {
            $updateSql .= ' AND is_normal_universe = 1';
        }

        $update = $pdo->prepare($updateSql);

        $updated = 0;
        $seen = 0;
        foreach (SdeJsonlReader::read($file) as $row) {
            $payload = is_array($row['value'] ?? null) ? $row['value'] : $row;
            $id = (int) ($payload['solarSystemID'] ?? $payload['solarSystemId'] ?? $payload['id'] ?? $payload['_key'] ?? $payload['key'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $securityTrue = SecurityStatus::normalizeSecurityRaw((float) ($payload['securityStatus'] ?? $payload['security'] ?? 0.0));
            $securityDisplay = SecurityStatus::secDisplayFromRaw($securityTrue);
            $secClass = SecurityStatus::secBandFromDisplay($securityDisplay);

            $update->execute([
                'id' => $id,
                'security_true' => $securityTrue,
                'security_display' => $securityDisplay,
                'sec_class' => $secClass,
            ]);

            $seen++;
            if ($update->rowCount() > 0) {
                $updated++;
            }
        }

        $output->writeln(sprintf('<info>Processed JSONL systems: %d</info>', $seen));
        $output->writeln(sprintf('<info>Updated DB systems: %d</info>', $updated));

        return Command::SUCCESS;
    }
}
