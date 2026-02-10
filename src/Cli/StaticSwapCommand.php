<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Everoute\Universe\StaticMetaRepository;
use Everoute\Universe\StaticTableResolver;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class StaticSwapCommand extends Command
{
    use DbAware;

    protected static $defaultName = 'static:swap';

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Swap active static tables to a completed build id')
            ->addOption('build-id', null, InputOption::VALUE_REQUIRED, 'Build id produced by static:rebuild --shadow');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $buildId = trim((string) $input->getOption('build-id'));
        if ($buildId === '') {
            $output->writeln('<error>--build-id is required.</error>');
            return Command::FAILURE;
        }

        $connection = $this->connection();
        $pdo = $connection->pdo();
        $meta = new StaticMetaRepository($connection);
        $meta->ensureInitialized($meta->latestSdeBuildNumber());
        $resolver = new StaticTableResolver($connection, $meta);

        $pairs = [
            StaticTableResolver::GATE_DISTANCES,
            StaticTableResolver::JUMP_NEIGHBORS,
            StaticTableResolver::REGION_HIERARCHY,
            StaticTableResolver::CONSTELLATION_HIERARCHY,
        ];

        foreach ($pairs as $base) {
            $shadow = $resolver->writeTable($base, $buildId);
            if (!$this->tableExists($pdo, $shadow)) {
                $output->writeln(sprintf('<error>Missing shadow table `%s`.</error>', $shadow));
                return Command::FAILURE;
            }
            if (!$this->tableExists($pdo, $base)) {
                $output->writeln(sprintf('<error>Missing base table `%s`.</error>', $base));
                return Command::FAILURE;
            }
        }

        $lockStmt = $pdo->prepare('SELECT GET_LOCK(:name, 30)');
        $lockStmt->execute(['name' => 'everoute_static_swap']);
        if ((int) $lockStmt->fetchColumn() !== 1) {
            $output->writeln('<error>Unable to acquire swap lock.</error>');
            return Command::FAILURE;
        }

        try {
            $archiveSuffix = gmdate('YmdHis');
            $renameParts = [];
            foreach ($pairs as $base) {
                $shadow = $resolver->writeTable($base, $buildId);
                $archive = sprintf('%s__archive_%s', $base, $archiveSuffix);
                $renameParts[] = sprintf('`%s` TO `%s`', $base, $archive);
                $renameParts[] = sprintf('`%s` TO `%s`', $shadow, $base);
            }

            $pdo->exec('RENAME TABLE ' . implode(', ', $renameParts));

            $pdo->beginTransaction();
            $meta->setActiveBuildId($buildId);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $output->writeln(sprintf('<error>Swap failed: %s</error>', $e->getMessage()));
            $output->writeln('<error>active_build_id was not updated.</error>');
            return Command::FAILURE;
        } finally {
            $release = $pdo->prepare('DO RELEASE_LOCK(:name)');
            $release->execute(['name' => 'everoute_static_swap']);
        }

        $output->writeln(sprintf('<info>Swap complete. active_build_id=%s</info>', $buildId));
        return Command::SUCCESS;
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table'
        );
        $stmt->execute(['table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
