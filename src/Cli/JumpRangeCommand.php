<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Everoute\Routing\JumpMath;
use Everoute\Routing\JumpRangeCalculator;
use Everoute\Routing\ShipRules;
use Everoute\Routing\SystemLookup;
use Everoute\Security\Logger;
use Everoute\Universe\JumpNeighborRepository;
use Everoute\Universe\SystemRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class JumpRangeCommand extends Command
{
    use DbAware;

    protected static $defaultName = 'jump:range';

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('List jump range neighbors for a system and ship')
            ->addOption('system', null, InputOption::VALUE_REQUIRED, 'Origin system name or ID')
            ->addOption('ship', null, InputOption::VALUE_REQUIRED, 'Ship type (carrier, dread, fax, super, titan, jump_freighter)')
            ->addOption('jdc', null, InputOption::VALUE_REQUIRED, 'Jump Drive Calibration skill level (0-5)', '5')
            ->addOption('range', null, InputOption::VALUE_REQUIRED, 'Jump range in LY or "auto"', 'auto')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit output rows', '200')
            ->addOption('contains', null, InputOption::VALUE_REQUIRED, 'Check whether a destination is reachable');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $systemValue = (string) $input->getOption('system');
        $shipValue = (string) $input->getOption('ship');
        $skillLevel = (int) $input->getOption('jdc');
        $rangeValue = (string) $input->getOption('range');
        $limit = max(1, (int) $input->getOption('limit'));
        $containsValue = (string) $input->getOption('contains');

        if ($systemValue === '' || $shipValue === '') {
            $output->writeln('<error>Provide --system and --ship.</error>');
            return Command::FAILURE;
        }

        $connection = $this->connection();
        $systemsRepo = new SystemRepository($connection);
        $systemLookup = new SystemLookup($systemsRepo);
        $origin = $systemLookup->resolveByNameOrId($systemValue);
        if ($origin === null) {
            $output->writeln('<error>Origin system not found.</error>');
            return Command::FAILURE;
        }

        $shipRules = new ShipRules();
        $shipType = $shipRules->normalizeShipType($shipValue);
        if (!$shipRules->isSupported($shipType)) {
            $output->writeln('<error>Unsupported ship type.</error>');
            return Command::FAILURE;
        }

        $skillLevel = max(0, min(5, $skillLevel));
        $rangeCalculator = new JumpRangeCalculator(__DIR__ . '/../../config/ships.php', __DIR__ . '/../../config/jump_ranges.php');
        if ($rangeValue === 'auto') {
            $effectiveRange = $rangeCalculator->effectiveRange($shipType, $skillLevel);
            if ($effectiveRange === null) {
                $output->writeln('<error>Unable to compute effective range for ship.</error>');
                return Command::FAILURE;
            }
        } elseif (is_numeric($rangeValue)) {
            $effectiveRange = round((float) $rangeValue, 2);
        } else {
            $output->writeln('<error>Range must be numeric or "auto".</error>');
            return Command::FAILURE;
        }

        $bucketFloor = (int) floor($effectiveRange);
        if ($bucketFloor < 1) {
            $output->writeln('<error>Effective range is below 1 LY.</error>');
            return Command::FAILURE;
        }
        $bucket = min(10, $bucketFloor);

        $output->writeln(sprintf(
            '<info>Origin: %s (%d) sec=%.2f</info>',
            $origin['name'] ?? (string) $origin['id'],
            (int) $origin['id'],
            (float) ($origin['security'] ?? 0.0)
        ));
        $output->writeln(sprintf('Ship: %s (JDC %d)', $shipType, $skillLevel));
        $output->writeln(sprintf('Effective range: %.2f LY (bucket floor %d, clamped %d)', $effectiveRange, $bucketFloor, $bucket));

        $logger = new Logger();
        $neighborRepo = new JumpNeighborRepository($connection, $logger);
        $neighborPayload = $neighborRepo->loadSystemNeighbors((int) $origin['id'], $bucket);
        if ($neighborPayload === null) {
            $output->writeln('<comment>No jump neighbor row found.</comment>');
            return Command::SUCCESS;
        }

        $dbCount = $neighborPayload['neighbor_count'];
        $decoded = $neighborPayload['neighbor_ids'];
        $decodedCount = count($decoded);

        $systems = [];
        foreach ($systemsRepo->listForRouting() as $system) {
            $systems[(int) $system['id']] = $system;
        }

        $rows = [];
        $filteredCount = 0;
        $rawHasContains = false;
        $filteredHasContains = false;
        $containsId = null;
        if ($containsValue !== '') {
            $containsSystem = $systemLookup->resolveByNameOrId($containsValue);
            if ($containsSystem !== null) {
                $containsId = (int) $containsSystem['id'];
            }
        }

        foreach ($decoded as $neighborId) {
            if ($containsId !== null && $neighborId === $containsId) {
                $rawHasContains = true;
            }
            $neighbor = $systems[$neighborId] ?? null;
            if ($neighbor === null) {
                continue;
            }
            if (!$shipRules->isSystemAllowed($shipType, $neighbor, true)) {
                $filteredCount++;
                continue;
            }
            if ($containsId !== null && $neighborId === $containsId) {
                $filteredHasContains = true;
            }
            $distance = JumpMath::distanceLy($origin, $neighbor);
            $rows[] = [
                'name' => (string) ($neighbor['name'] ?? $neighborId),
                'id' => $neighborId,
                'security' => (float) ($neighbor['security'] ?? 0.0),
                'distance' => $distance,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $a['distance'] <=> $b['distance']);
        $afterFilterCount = count($rows);
        $rows = array_slice($rows, 0, $limit);

        $output->writeln('');
        $output->writeln(sprintf('%-24s | %-9s | %-8s | %s', 'neighbor_name', 'neighbor_id', 'security', 'distance_ly'));
        $output->writeln(str_repeat('-', 64));
        foreach ($rows as $row) {
            $output->writeln(sprintf(
                '%-24s | %-9d | %-8.2f | %.2f',
                $row['name'],
                $row['id'],
                $row['security'],
                $row['distance']
            ));
        }

        $output->writeln('');
        $output->writeln(sprintf('db_neighbor_count: %d', $dbCount));
        $output->writeln(sprintf('decoded_count: %d', $decodedCount));
        $output->writeln(sprintf('after_filter_count: %d', $afterFilterCount));
        if ($filteredCount > 0) {
            $output->writeln(sprintf('filtered_by_ship_rules: %d', $filteredCount));
        }

        if ($containsId !== null) {
            $containsLabel = $containsValue !== '' ? $containsValue : (string) $containsId;
            if ($filteredHasContains) {
                $output->writeln(sprintf('Contains %s: yes (reachable)', $containsLabel));
            } elseif ($rawHasContains) {
                $output->writeln(sprintf('Contains %s: no (filtered by ship rules)', $containsLabel));
            } else {
                $output->writeln(sprintf('Contains %s: no', $containsLabel));
            }
        }

        return Command::SUCCESS;
    }
}
