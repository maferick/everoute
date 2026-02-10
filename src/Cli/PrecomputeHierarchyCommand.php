<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Everoute\Universe\StaticTableResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PrecomputeHierarchyCommand extends Command
{
    use DbAware;

    protected static $defaultName = 'precompute:hierarchy';

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Precompute static hierarchy tables for systems')
            ->addOption('build-id', null, InputOption::VALUE_REQUIRED, 'Optional build id for shadow-table writes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $buildId = trim((string) $input->getOption('build-id'));

        $connection = $this->connection();
        $pdo = $connection->pdo();
        $resolver = new StaticTableResolver($connection);
        $regionTable = $resolver->writeTable(StaticTableResolver::REGION_HIERARCHY, $buildId !== '' ? $buildId : null);
        $constellationTable = $resolver->writeTable(StaticTableResolver::CONSTELLATION_HIERARCHY, $buildId !== '' ? $buildId : null);

        $pdo->beginTransaction();
        $pdo->exec(sprintf('TRUNCATE TABLE `%s`', $regionTable));
        $pdo->exec(sprintf('TRUNCATE TABLE `%s`', $constellationTable));

        $pdo->exec(sprintf(
            'INSERT INTO `%s` (system_id, region_id, updated_at)
             SELECT id, region_id, NOW() FROM systems',
            $regionTable
        ));

        $pdo->exec(sprintf(
            'INSERT INTO `%s` (system_id, constellation_id, region_id, updated_at)
             SELECT id, constellation_id, region_id, NOW() FROM systems',
            $constellationTable
        ));
        $pdo->commit();

        $output->writeln('<info>Hierarchy precompute complete.</info>');
        return Command::SUCCESS;
    }
}
