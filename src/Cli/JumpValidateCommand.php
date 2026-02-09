<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Everoute\Universe\JumpNeighborValidator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class JumpValidateCommand extends Command
{
    use DbAware;

    protected static $defaultName = 'jump:validate';

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Validate jump neighbor precompute data')
            ->addOption('ranges', null, InputOption::VALUE_REQUIRED, 'Comma-separated LY ranges to validate (default 1-10)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ranges = $this->parseRanges((string) $input->getOption('ranges'));
        $validator = new JumpNeighborValidator($this->connection());

        $monotonic = $validator->validateMonotonicity($ranges);
        foreach ($monotonic['violations'] as $systemId => $entries) {
            foreach ($entries as $entry) {
                $output->writeln(sprintf(
                    '<error>System %d non-monotonic at %d LY (prev=%d, count=%d).</error>',
                    $systemId,
                    $entry['range'],
                    $entry['prev'],
                    $entry['count']
                ));
            }
        }

        $completeness = $validator->validateCompleteness($ranges);
        foreach ($completeness['missing'] as $systemId => $missingCount) {
            $output->writeln(sprintf(
                '<error>System %d missing %d jump neighbor rows.</error>',
                $systemId,
                $missingCount
            ));
        }

        $output->writeln(sprintf(
            'systems_checked=%d, violations_found=%d, missing_rows_found=%d',
            $monotonic['systems_checked'],
            $monotonic['violations_found'],
            $completeness['missing_rows_found']
        ));

        if ($monotonic['violations_found'] > 0 || $completeness['missing_rows_found'] > 0) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /** @return int[] */
    private function parseRanges(string $value): array
    {
        if (trim($value) === '') {
            return range(1, 10);
        }
        $parts = array_filter(array_map('trim', explode(',', $value)), static fn (string $part): bool => $part !== '');
        $ranges = [];
        foreach ($parts as $part) {
            if (is_numeric($part)) {
                $ranges[] = (int) round((float) $part);
            }
        }
        $ranges = array_values(array_unique($ranges));
        sort($ranges);
        return $ranges;
    }
}
