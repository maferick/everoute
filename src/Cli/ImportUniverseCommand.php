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
            ->setDescription('Import universe data from JSON or CSV')
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
        $stmt = $pdo->prepare('INSERT INTO systems (id, name, security, region_id, constellation_id, x, y, z, system_size_au) VALUES (:id, :name, :security, :region_id, :constellation_id, :x, :y, :z, :system_size_au) ON DUPLICATE KEY UPDATE name=VALUES(name), security=VALUES(security), region_id=VALUES(region_id), constellation_id=VALUES(constellation_id), x=VALUES(x), y=VALUES(y), z=VALUES(z), system_size_au=VALUES(system_size_au)');
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
            ]);
        }
    }

    private function importStargates(PDO $pdo, array $stargates): void
    {
        $pdo->exec('DELETE FROM stargates');
        $stmt = $pdo->prepare('INSERT INTO stargates (from_system_id, to_system_id) VALUES (:from_id, :to_id)');
        foreach ($stargates as $edge) {
            $stmt->execute([
                'from_id' => $edge['from_system_id'],
                'to_id' => $edge['to_system_id'],
            ]);
        }
    }

    private function importStations(PDO $pdo, array $stations): void
    {
        $stmt = $pdo->prepare('INSERT INTO stations (station_id, system_id, name, type, is_npc) VALUES (:station_id, :system_id, :name, :type, :is_npc) ON DUPLICATE KEY UPDATE name=VALUES(name), type=VALUES(type), is_npc=VALUES(is_npc)');
        foreach ($stations as $station) {
            $stmt->execute([
                'station_id' => $station['station_id'],
                'system_id' => $station['system_id'],
                'name' => $station['name'],
                'type' => $station['type'] ?? 'npc',
                'is_npc' => $station['is_npc'] ?? 1,
            ]);
        }
    }
}
