<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Everoute\Routing\SystemLookup;
use Everoute\Security\Logger;
use Everoute\Universe\JumpNeighborRepository;
use Everoute\Universe\SystemRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class JumpInspectCommand extends Command
{
    use DbAware;

    protected static $defaultName = 'jump:inspect';

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Inspect jump neighbor payloads for a system')
            ->addOption('system', null, InputOption::VALUE_REQUIRED, 'System name or ID to inspect')
            ->addOption('range', null, InputOption::VALUE_REQUIRED, 'Jump range bucket (LY)', '7')
            ->addOption('raw', null, InputOption::VALUE_NONE, 'Show only raw neighbor IDs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $systemValue = (string) $input->getOption('system');
        $rangeBucket = (int) $input->getOption('range');
        $rawOnly = (bool) $input->getOption('raw');

        if ($systemValue === '' || $rangeBucket <= 0) {
            $output->writeln('<error>Provide a valid --system and --range.</error>');
            return Command::FAILURE;
        }

        $connection = $this->connection();
        $systemsRepo = new SystemRepository($connection);
        $systemLookup = new SystemLookup($systemsRepo);
        $system = $systemLookup->resolveByNameOrId($systemValue);
        if ($system === null) {
            $output->writeln('<error>System not found.</error>');
            return Command::FAILURE;
        }

        $logger = new Logger();
        $repo = new JumpNeighborRepository($connection, $logger);
        $neighbors = $repo->loadSystemNeighbors((int) $system['id'], $rangeBucket);
        if ($neighbors === null) {
            $output->writeln(sprintf(
                '<comment>No jump neighbor row found for system %s at %d LY.</comment>',
                $system['name'] ?? (string) $system['id'],
                $rangeBucket
            ));
            return Command::SUCCESS;
        }

        $decoded = $neighbors['neighbor_ids'];
        $decodedCount = count($decoded);
        $dbCount = $neighbors['neighbor_count'];
        $output->writeln(sprintf('<info>System: %s (%d)</info>', $system['name'] ?? '', (int) $system['id']));
        $output->writeln(sprintf('Range: %d LY', $rangeBucket));
        $output->writeln(sprintf('DB neighbor_count: %d', $dbCount));
        $output->writeln(sprintf('Decoded neighbors: %d', $decodedCount));

        $previewIds = array_slice($decoded, 0, 20);
        $output->writeln(sprintf(
            'First 20 neighbor IDs: %s',
            $previewIds === [] ? '(none)' : implode(', ', $previewIds)
        ));

        if (!$rawOnly && $previewIds !== []) {
            $output->writeln('First 20 neighbors resolved:');
            foreach ($previewIds as $neighborId) {
                $neighbor = $systemsRepo->findByNameOrId((string) $neighborId);
                $name = $neighbor['name'] ?? '(unknown)';
                $security = $neighbor !== null ? (float) ($neighbor['security'] ?? 0.0) : 0.0;
                $output->writeln(sprintf(' - %s (%d) sec=%.2f', $name, $neighborId, $security));
            }
        }

        return Command::SUCCESS;
    }
}
