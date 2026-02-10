<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PrecomputeAllCommand extends Command
{
    protected static $defaultName = 'precompute:all';

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Run all map precompute commands in sequence')
            ->addOption('hours', null, InputOption::VALUE_REQUIRED, 'Stop heavy jobs after N hours (0 = no limit)', '1')
            ->addOption('resume', null, InputOption::VALUE_NONE, 'Resume heavy jobs from the last checkpoint')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Sleep seconds between systems/sources for heavy jobs', '0')
            ->addOption('ranges', null, InputOption::VALUE_REQUIRED, 'Comma-separated LY ranges for jump:precompute')
            ->addOption('max-hops', null, InputOption::VALUE_REQUIRED, 'Maximum hops for precompute:gate-distances', '20')
            ->addOption('include-wormholes', null, InputOption::VALUE_NONE, 'Include wormhole and non-normal-universe systems in heavy precompute jobs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $application = $this->getApplication();
        if ($application === null) {
            $output->writeln('<error>Console application is not available.</error>');
            return Command::FAILURE;
        }

        $hours = (string) $input->getOption('hours');
        $resume = (bool) $input->getOption('resume');
        $sleep = (string) $input->getOption('sleep');
        $ranges = trim((string) $input->getOption('ranges'));
        $maxHops = (string) $input->getOption('max-hops');
        $includeWormholes = (bool) $input->getOption('include-wormholes');

        $steps = [
            ['command' => 'precompute:system-facts', 'args' => []],
            ['command' => 'map:derive', 'args' => []],
            [
                'command' => 'precompute:gate-distances',
                'args' => [
                    '--hours' => $hours,
                    '--max-hops' => $maxHops,
                    '--sleep' => $sleep,
                ],
            ],
            [
                'command' => 'jump:precompute',
                'args' => [
                    '--hours' => $hours,
                    '--sleep' => $sleep,
                ],
            ],
        ];

        if ($ranges !== '') {
            $steps[3]['args']['--ranges'] = $ranges;
        }

        if ($includeWormholes) {
            $steps[0]['args']['--include-wormholes'] = true;
            $steps[1]['args']['--include-wormholes'] = true;
            $steps[2]['args']['--include-wormholes'] = true;
            $steps[3]['args']['--include-wormholes'] = true;
        }

        if ($resume) {
            $steps[2]['args']['--resume'] = true;
            $steps[3]['args']['--resume'] = true;
        }

        foreach ($steps as $step) {
            $commandName = (string) $step['command'];
            $startedAt = microtime(true);
            $output->writeln(sprintf('<info>Starting %s...</info>', $commandName));

            try {
                $command = $application->find($commandName);
                $arguments = ['command' => $commandName] + $step['args'];
                $status = $command->run(new ArrayInput($arguments), $output);
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>%s failed with exception: %s</error>', $commandName, $e->getMessage()));
                return Command::FAILURE;
            }

            if ($status !== Command::SUCCESS) {
                $output->writeln(sprintf('<error>%s failed with exit code %d.</error>', $commandName, $status));
                return Command::FAILURE;
            }

            $elapsed = microtime(true) - $startedAt;
            $output->writeln(sprintf('<info>Finished %s in %s.</info>', $commandName, $this->formatDuration($elapsed)));
        }

        $output->writeln('<info>All precompute steps completed.</info>');
        return Command::SUCCESS;
    }

    private function formatDuration(float $seconds): string
    {
        $seconds = max(0, (int) round($seconds));
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;
        return sprintf('%02dh:%02dm:%02ds', $hours, $minutes, $secs);
    }
}

