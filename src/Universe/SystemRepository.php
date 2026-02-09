<?php

declare(strict_types=1);

namespace Everoute\Universe;

use Everoute\DB\Connection;

final class SystemRepository
{
    private ?bool $hasSecurityNav = null;

    public function __construct(private Connection $connection)
    {
    }

    public function findByNameOrId(string $value): ?array
    {
        $pdo = $this->connection->pdo();
        $selectFields = $this->systemSelectFields();
        if (ctype_digit($value)) {
            $stmt = $pdo->prepare("SELECT {$selectFields} FROM systems WHERE id = :id");
            $stmt->execute(['id' => (int) $value]);
        } else {
            $stmt = $pdo->prepare("SELECT {$selectFields} FROM systems WHERE name = :name");
            $stmt->execute(['name' => $value]);
        }
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function listAll(): array
    {
        $selectFields = $this->systemSelectFields();
        $stmt = $this->connection->pdo()->query("SELECT {$selectFields} FROM systems ORDER BY name");
        return $stmt->fetchAll();
    }

    public function listForRouting(): array
    {
        $selectFields = $this->systemSelectFields();
        $stmt = $this->connection->pdo()->query(
            "SELECT {$selectFields} FROM systems"
        );
        return $stmt->fetchAll();
    }

    public function neighbors(int $systemId): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT to_system_id FROM stargates WHERE from_system_id = :id');
        $stmt->execute(['id' => $systemId]);
        return array_map(static fn ($row) => (int) $row['to_system_id'], $stmt->fetchAll());
    }

    private function systemSelectFields(): string
    {
        if ($this->hasSecurityNavColumn()) {
            return 'id, name, security_nav AS security, security_raw, security_nav, region_id, constellation_id, has_npc_station, npc_station_count, system_size_au, x, y, z';
        }

        return 'id, name, security AS security, security_raw, security AS security_nav, region_id, constellation_id, has_npc_station, npc_station_count, system_size_au, x, y, z';
    }

    private function hasSecurityNavColumn(): bool
    {
        if ($this->hasSecurityNav !== null) {
            return $this->hasSecurityNav;
        }

        $stmt = $this->connection->pdo()->query(
            "SELECT COUNT(*) AS column_count FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'systems' AND column_name = 'security_nav'"
        );
        $row = $stmt->fetch();
        $this->hasSecurityNav = isset($row['column_count']) && (int) $row['column_count'] > 0;

        return $this->hasSecurityNav;
    }
}
