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
            ->setDescription('Audit/fix systems where security display/sec_class differ from security_true half-up 1dp')
            ->addOption('fix', null, InputOption::VALUE_NONE, 'Apply corrective UPDATE for mismatched rows')
            ->addOption('only-normal-universe', null, InputOption::VALUE_NONE, 'Restrict audit/fix to systems.is_normal_universe=1');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pdo = $this->connection()->pdo();
        $normalUniverseOnly = (bool) $input->getOption('only-normal-universe');

        $where = $normalUniverseOnly ? ' WHERE is_normal_universe = 1' : '';
        $rowsStmt = $pdo->query('SELECT id, name, is_normal_universe, security_true, security_display, security_raw, security_nav, security, sec_class FROM systems' . $where . ' ORDER BY id ASC');
        $rows = $rowsStmt !== false ? $rowsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $mismatches = [];
        foreach ($rows as $row) {
            $securityTrue = SecurityStatus::normalizeSecurityRaw((float) ($row['security_true'] ?? $row['security_raw'] ?? $row['security'] ?? 0.0));
            $expectedDisplay = SecurityStatus::secDisplayFromRaw($securityTrue);
            $expectedClass = SecurityStatus::secBandFromDisplay($expectedDisplay);

            $hasMismatch = abs((float) ($row['security_true'] ?? 0.0) - $securityTrue) >= 0.000001
                || abs((float) ($row['security_display'] ?? 0.0) - $expectedDisplay) >= 0.0001
                || abs((float) ($row['security'] ?? 0.0) - $expectedDisplay) >= 0.0001
                || abs((float) ($row['security_nav'] ?? 0.0) - $expectedDisplay) >= 0.0001
                || (string) ($row['sec_class'] ?? '') !== $expectedClass;

            if ($hasMismatch) {
                $row['expected_true'] = $securityTrue;
                $row['expected_display'] = $expectedDisplay;
                $row['expected_sec_class'] = $expectedClass;
                $mismatches[] = $row;
            }
        }

        $output->writeln(sprintf('<info>Security mismatches: %d</info>', count($mismatches)));
        $output->writeln('<comment>Audit SQL equivalent: SELECT name, security_true, security_display, sec_class, security_raw, security_nav, security FROM systems WHERE ABS(security_display - ROUND(security_true, 1)) > 0.0001 OR (security_display >= 0.5 AND sec_class <> "high") OR (security_display >= 0.1 AND security_display < 0.5 AND sec_class <> "low") OR (security_display < 0.1 AND sec_class <> "null");</comment>');

        foreach (array_slice($mismatches, 0, 50) as $r) {
            $output->writeln(sprintf(
                'id=%d name=%s true=%.6f display=%.1f nav=%.1f security=%.1f sec_class=%s expected_display=%.1f expected_sec_class=%s',
                (int) $r['id'],
                (string) $r['name'],
                (float) $r['security_true'],
                (float) $r['security_display'],
                (float) $r['security_nav'],
                (float) $r['security'],
                (string) $r['sec_class'],
                (float) $r['expected_display'],
                (string) $r['expected_sec_class']
            ));
        }

        if ((bool) $input->getOption('fix') && !empty($mismatches)) {
            $update = $pdo->prepare('UPDATE systems SET security_true = :security_true, security_display = :security_display, security = :security, security_nav = :security_nav, sec_class = :sec_class WHERE id = :id');
            foreach ($mismatches as $row) {
                $update->execute([
                    'id' => (int) $row['id'],
                    'security_true' => (float) $row['expected_true'],
                    'security_display' => (float) $row['expected_display'],
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
