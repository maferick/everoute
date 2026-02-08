<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Everoute\Config\Env;
use PDO;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class ImportUniverseCommand extends Command
{
    use DbAware;

    protected static $defaultName = 'import:universe';

    protected function configure(): void
    {
        $this
            ->setName(self::$defaultName)
            ->setDescription('Legacy import: universe data from JSON (use sde:install for CCP SDE)')
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Path to data file')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'json|csv', 'json');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = (string) $input->getOption('file');
        $format = (string) $input->getOption('format');
        if ($file === '' || !file_exists($file)) {
            $output->writeln('<error>File not found.</error>');
            return Command::FAILURE;
        }

        $pdo = $this->connection()->pdo();
        $pdo->beginTransaction();

        if ($format === 'json') {
            $payload = json_decode((string) file_get_contents($file), true);
            if (!is_array($payload)) {
                $output->writeln('<error>Invalid JSON.</error>');
                return Command::FAILURE;
            }
            $this->importSystems($pdo, $payload['systems'] ?? []);
            $this->importStargates($pdo, $payload['stargates'] ?? []);
            $this->importStations($pdo, $payload['stations'] ?? []);
            $this->updateRegionalGates($pdo);
        } else {
            $output->writeln('<error>CSV format not yet supported; use JSON.</error>');
            return Command::FAILURE;
        }

        $pdo->commit();
        $output->writeln('<info>Universe import complete.</info>');
        return Command::SUCCESS;
    }

    private function importSystems(PDO $pdo, array $systems): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare('INSERT INTO systems (id, name, security, region_id, constellation_id, x, y, z, system_size_au, updated_at) VALUES (:id, :name, :security, :region_id, :constellation_id, :x, :y, :z, :system_size_au, :updated_at) ON DUPLICATE KEY UPDATE name=VALUES(name), security=VALUES(security), region_id=VALUES(region_id), constellation_id=VALUES(constellation_id), x=VALUES(x), y=VALUES(y), z=VALUES(z), system_size_au=VALUES(system_size_au), updated_at=VALUES(updated_at)');
        foreach ($systems as $system) {
            $stmt->execute([
                'id' => $system['id'],
                'name' => $system['name'],
                'security' => $system['security'],
                'region_id' => $system['region_id'] ?? null,
                'constellation_id' => $system['constellation_id'] ?? null,
                'x' => $system['x'] ?? 0,
                'y' => $system['y'] ?? 0,
                'z' => $system['z'] ?? 0,
                'system_size_au' => $system['system_size_au'] ?? 1.0,
                'updated_at' => $now,
            ]);
        }
    }

    private function importStargates(PDO $pdo, array $stargates): void
    {
        $pdo->exec('DELETE FROM stargates');
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $hasRegional = $this->tableHasColumn($pdo, 'stargates', 'is_regional_gate');
        $stmtWithId = $pdo->prepare($hasRegional
            ? 'INSERT INTO stargates (id, from_system_id, to_system_id, is_regional_gate, updated_at) VALUES (:id, :from_id, :to_id, :is_regional_gate, :updated_at)'
            : 'INSERT INTO stargates (id, from_system_id, to_system_id, updated_at) VALUES (:id, :from_id, :to_id, :updated_at)'
        );
        $stmt = $pdo->prepare($hasRegional
            ? 'INSERT INTO stargates (from_system_id, to_system_id, is_regional_gate, updated_at) VALUES (:from_id, :to_id, :is_regional_gate, :updated_at)'
            : 'INSERT INTO stargates (from_system_id, to_system_id, updated_at) VALUES (:from_id, :to_id, :updated_at)'
        );
        foreach ($stargates as $edge) {
            $payload = [
                'from_id' => $edge['from_system_id'],
                'to_id' => $edge['to_system_id'],
                'updated_at' => $now,
            ];
            if ($hasRegional) {
                $payload['is_regional_gate'] = (int) ($edge['is_regional_gate'] ?? 0);
            }
            if (isset($edge['id'])) {
                $payload['id'] = $edge['id'];
                $stmtWithId->execute($payload);
            } else {
                $stmt->execute($payload);
            }
        }
    }

    private function importStations(PDO $pdo, array $stations): void
    {
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $stmt = $pdo->prepare('INSERT INTO stations (station_id, system_id, name, type, is_npc, updated_at) VALUES (:station_id, :system_id, :name, :type, :is_npc, :updated_at) ON DUPLICATE KEY UPDATE name=VALUES(name), type=VALUES(type), is_npc=VALUES(is_npc), updated_at=VALUES(updated_at)');
        foreach ($stations as $station) {
            $stmt->execute([
                'station_id' => $station['station_id'],
                'system_id' => $station['system_id'],
                'name' => $station['name'],
                'type' => $station['type'] ?? 'npc',
                'is_npc' => $station['is_npc'] ?? 1,
                'updated_at' => $now,
            ]);
        }
    }

    private function updateRegionalGates(PDO $pdo): void
    {
        if (!$this->tableHasColumn($pdo, 'stargates', 'is_regional_gate')) {
            return;
        }

        $pdo->exec(
            'UPDATE stargates s
            JOIN systems a ON s.from_system_id = a.id
            JOIN systems b ON s.to_system_id = b.id
            SET s.is_regional_gate = CASE
                WHEN a.region_id IS NOT NULL AND b.region_id IS NOT NULL AND a.region_id <> b.region_id THEN 1
                ELSE 0
            END'
        );
    }

    private function tableHasColumn(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column'
        );
        $stmt->execute(['table' => $table, 'column' => $column]);

        return (bool) $stmt->fetchColumn();
    }
}
