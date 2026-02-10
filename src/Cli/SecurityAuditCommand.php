<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Everoute\Universe\SecurityStatus;
use PDO;
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
            ->setDescription('Audit/fix systems where security display/sec_class differ from security_raw half-up 1dp')
            ->addOption('fix', null, InputOption::VALUE_NONE, 'Apply corrective UPDATE for mismatched rows');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pdo = $this->connection()->pdo();

        $rowsStmt = $pdo->query('SELECT id, name, security_raw, security_nav, security, sec_class FROM systems ORDER BY id ASC');
        $rows = $rowsStmt !== false ? $rowsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $mismatches = [];
        foreach ($rows as $row) {
            $securityTrue = SecurityStatus::normalizeSecurityRaw((float) ($row['security_raw'] ?? $row['security'] ?? 0.0));
            $expectedDisplay = SecurityStatus::secDisplayFromRaw($securityTrue);
            $expectedClass = SecurityStatus::secBandFromDisplay($expectedDisplay);

            $hasMismatch = abs((float) ($row['security'] ?? 0.0) - $expectedDisplay) >= 0.0001
                || abs((float) ($row['security_nav'] ?? 0.0) - $expectedDisplay) >= 0.0001
                || (string) ($row['sec_class'] ?? '') !== $expectedClass;

            if ($hasMismatch) {
                $row['expected_display'] = $expectedDisplay;
                $row['expected_sec_class'] = $expectedClass;
                $mismatches[] = $row;
            }
        }

        $output->writeln(sprintf('<info>Security mismatches: %d</info>', count($mismatches)));

        foreach (array_slice($mismatches, 0, 50) as $r) {
            $output->writeln(sprintf(
                'id=%d name=%s raw=%.4f nav=%.1f security=%.1f sec_class=%s expected_display=%.1f expected_sec_class=%s',
                (int) $r['id'],
                (string) $r['name'],
                (float) $r['security_raw'],
                (float) $r['security_nav'],
                (float) $r['security'],
                (string) $r['sec_class'],
                (float) $r['expected_display'],
                (string) $r['expected_sec_class']
            ));
        }

        if ((bool) $input->getOption('fix') && !empty($mismatches)) {
            $update = $pdo->prepare('UPDATE systems SET security = :security, security_nav = :security_nav, sec_class = :sec_class WHERE id = :id');
            foreach ($mismatches as $row) {
                $update->execute([
                    'id' => (int) $row['id'],
                    'security' => (float) $row['expected_display'],
                    'security_nav' => (float) $row['expected_display'],
                    'sec_class' => (string) $row['expected_sec_class'],
                ]);
            }

            $output->writeln(sprintf('<info>Corrective update applied to %d rows.</info>', count($mismatches)));
        }

        return Command::SUCCESS;
    }
}
