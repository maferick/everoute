<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class SecurityAuditCommand extends Command
{
    use DbAware;

    protected static $defaultName = 'security:audit';

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Audit/fix systems where security/security_nav differ from floored security_raw')
            ->addOption('fix', null, InputOption::VALUE_NONE, 'Apply corrective UPDATE for mismatched rows');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pdo = $this->connection()->pdo();
        $expectedExpr = 'FLOOR(COALESCE(security_raw, security) * 10) / 10';
        $mismatchWhere = "ABS(COALESCE(security_nav, {$expectedExpr}) - ({$expectedExpr})) >= 0.1 OR ABS(COALESCE(security, {$expectedExpr}) - ({$expectedExpr})) >= 0.1";

        $countStmt = $pdo->query("SELECT COUNT(*) AS total FROM systems WHERE {$mismatchWhere}");
        $row = $countStmt !== false ? $countStmt->fetch() : [];
        $total = (int) ($row['total'] ?? 0);
        $output->writeln(sprintf('<info>Security mismatches: %d</info>', $total));

        $detailStmt = $pdo->query(
            "SELECT id, name, security_raw, security_nav, security, {$expectedExpr} AS expected
             FROM systems
             WHERE {$mismatchWhere}
             ORDER BY ABS(COALESCE(security, {$expectedExpr}) - ({$expectedExpr})) DESC, id ASC
             LIMIT 50"
        );
        $rows = $detailStmt !== false ? $detailStmt->fetchAll() : [];

        foreach ($rows as $r) {
            $output->writeln(sprintf(
                'id=%d name=%s raw=%.4f nav=%.1f security=%.1f expected=%.1f',
                (int) $r['id'],
                (string) $r['name'],
                (float) $r['security_raw'],
                (float) $r['security_nav'],
                (float) $r['security'],
                (float) $r['expected']
            ));
        }

        if ((bool) $input->getOption('fix')) {
            $updated = $pdo->exec(
                "UPDATE systems
                 SET security_nav = {$expectedExpr},
                     security = {$expectedExpr},
                     sec_class = CASE
                        WHEN {$expectedExpr} >= 0.5 THEN 'high'
                        WHEN {$expectedExpr} >= 0.0 THEN 'low'
                        ELSE 'null'
                     END"
            );
            $output->writeln(sprintf('<info>Corrective update applied to %d rows.</info>', (int) $updated));
        }

        return Command::SUCCESS;
    }
}
