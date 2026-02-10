<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Everoute\Universe\StaticArtifactShadowManager;
use Everoute\Universe\StaticMetaRepository;
use Everoute\Universe\StaticTableResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class StaticRebuildCommand extends Command
{
    use DbAware;

    protected static $defaultName = 'static:rebuild';

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Rebuild static artifacts, optionally into shadow tables')
            ->addOption('shadow', null, InputOption::VALUE_NONE, 'Write outputs only to build-scoped shadow tables')
            ->addOption('build-id', null, InputOption::VALUE_REQUIRED, 'Build id override (defaults to generated value)')
            ->addOption('hours', null, InputOption::VALUE_REQUIRED, 'Hours limit for heavy precompute commands', '1')
            ->addOption('ranges', null, InputOption::VALUE_REQUIRED, 'Comma-separated LY ranges for jump precompute')
            ->addOption('max-hops', null, InputOption::VALUE_REQUIRED, 'Maximum hops for gate distance precompute', '20')
            ->addOption('include-wormholes', null, InputOption::VALUE_NONE, 'Include wormhole and non-normal-universe systems');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $connection = $this->connection();
        $meta = new StaticMetaRepository($connection);
        $meta->ensureInitialized($meta->latestSdeBuildNumber());

        $shadow = (bool) $input->getOption('shadow');
        $buildId = trim((string) $input->getOption('build-id'));
        if ($buildId === '') {
            $buildId = sprintf('b%s', gmdate('YmdHis'));
        }

        if ($shadow) {
            $resolver = new StaticTableResolver($connection, $meta);
            $shadowManager = new StaticArtifactShadowManager($connection, $resolver);
            $shadowManager->ensureShadowTables($buildId);
            $output->writeln(sprintf('<info>Shadow mode enabled for build_id=%s</info>', $buildId));
        } else {
            $buildId = '';
        }

        $steps = [
            ['command' => 'precompute:system-facts', 'args' => []],
            ['command' => 'precompute:hierarchy', 'args' => ['--build-id' => $buildId]],
            ['command' => 'precompute:gate-distances', 'args' => [
                '--hours' => (string) $input->getOption('hours'),
                '--max-hops' => (string) $input->getOption('max-hops'),
                '--build-id' => $buildId,
            ]],
            ['command' => 'jump:precompute', 'args' => [
                '--hours' => (string) $input->getOption('hours'),
                '--build-id' => $buildId,
            ]],
        ];

        $ranges = trim((string) $input->getOption('ranges'));
        if ($ranges !== '') {
            $steps[3]['args']['--ranges'] = $ranges;
        }
        if ((bool) $input->getOption('include-wormholes')) {
            $steps[0]['args']['--include-wormholes'] = true;
            $steps[2]['args']['--include-wormholes'] = true;
            $steps[3]['args']['--include-wormholes'] = true;
        }

        foreach ($steps as $step) {
            $name = $step['command'];
            $command = $this->getApplication()?->find($name);
            if ($command === null) {
                $output->writeln(sprintf('<error>Missing command: %s</error>', $name));
                return Command::FAILURE;
            }

            $args = ['command' => $name];
            foreach ($step['args'] as $key => $value) {
                if ($value === '' || $value === null || $value === false) {
                    continue;
                }
                $args[$key] = $value;
            }

            $output->writeln(sprintf('<comment>Running %s...</comment>', $name));
            $result = $command->run(new ArrayInput($args), $output);
            if ($result !== Command::SUCCESS) {
                $output->writeln(sprintf('<error>Failed at step %s. active_build_id not changed.</error>', $name));
                return $result;
            }
        }

        if ($shadow) {
            $output->writeln(sprintf('<info>Shadow rebuild complete. Build id: %s</info>', $buildId));
            $output->writeln(sprintf('<comment>Swap with: php bin/console static:swap --build-id=%s</comment>', $buildId));
        } else {
            $output->writeln('<info>Static rebuild complete.</info>');
        }

        return Command::SUCCESS;
    }
}
