<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class RebuildJumpNeighborsCommand extends Command
{
    use DbAware;

    protected static $defaultName = 'jump:rebuild';

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Rebuild jump neighbor cache using v1 encoding')
            ->addOption('hours', null, InputOption::VALUE_REQUIRED, 'Stop after N hours (0 = no limit)', '1')
            ->addOption('ranges', null, InputOption::VALUE_REQUIRED, 'Comma-separated LY ranges to compute (default config)')
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Sleep seconds between systems', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $connection = $this->connection();
        $pdo = $connection->pdo();
        if (!$this->tableExists($pdo, 'jump_neighbors')) {
            $output->writeln('<error>Missing jump_neighbors table. Run sql/schema.sql to initialize the database schema.</error>');
            return Command::FAILURE;
        }

        $output->writeln('<comment>Truncating jump_neighbors...</comment>');
        $pdo->exec('TRUNCATE TABLE jump_neighbors');

        $precompute = $this->getApplication()?->find('jump:precompute');
        if ($precompute === null) {
            $output->writeln('<error>jump:precompute command not found.</error>');
            return Command::FAILURE;
        }

        $args = new ArrayInput([
            'command' => 'jump:precompute',
            '--hours' => (string) $input->getOption('hours'),
            '--ranges' => (string) $input->getOption('ranges'),
            '--sleep' => (string) $input->getOption('sleep'),
        ]);

        return $precompute->run($args, $output);
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table'
        );
        $stmt->execute(['table' => $table]);
        return (bool) $stmt->fetchColumn();
    }
}
