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


    public function connection(): Connection
    {
        return $this->connection;
    }

    public function findByNameOrId(string $value): ?array
    {
        return $this->resolveSystem($value);
    }

    public function resolveSystem(string|int $input): ?array
    {
        if (is_int($input) || ctype_digit((string) $input)) {
            return $this->getSystemById((int) $input);
        }

        $name = trim((string) $input);
        if ($name === "") {
            return null;
        }

        $exact = $this->getSystemByNameExact($name);
        if ($exact !== null) {
            return $exact;
        }

        return $this->getSystemByNameCI($name);
    }

    public function getSystemById(int $id): ?array
    {
        $selectFields = $this->systemSelectFields();
        $stmt = $this->connection->pdo()->prepare("SELECT {$selectFields} FROM systems WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function getSystemByNameExact(string $name): ?array
    {
        $selectFields = $this->systemSelectFields();
        $stmt = $this->connection->pdo()->prepare("SELECT {$selectFields} FROM systems WHERE name = :name LIMIT 1");
        $stmt->execute(['name' => $name]);

        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function getSystemByNameCI(string $name): ?array
    {
        $selectFields = $this->systemSelectFields();
        $stmt = $this->connection->pdo()->prepare("SELECT {$selectFields} FROM systems WHERE LOWER(name) = LOWER(:name) LIMIT 1");
        $stmt->execute(['name' => $name]);

        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function listAll(): array
    {
        $selectFields = $this->systemSelectFields();
        $stmt = $this->connection->pdo()->query("SELECT {$selectFields} FROM systems ORDER BY name");
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function searchByName(string $query, int $limit = 10): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $limit = max(1, min(25, $limit));
        $selectFields = $this->systemSelectFields();
        $pdo = $this->connection->pdo();
        $stmt = $pdo->prepare(
            "SELECT {$selectFields}
             FROM systems
             WHERE name LIKE :prefix_match OR name LIKE :contains
             ORDER BY
                CASE
                    WHEN name = :exact THEN 0
                    WHEN name LIKE :prefix_rank THEN 1
                    ELSE 2
                END,
                LENGTH(name) ASC,
                security_nav DESC
             LIMIT {$limit}"
        );
        $stmt->execute([
            'exact' => $query,
            'prefix_match' => $query . '%',
            'prefix_rank' => $query . '%',
            'contains' => '%' . $query . '%',
        ]);

        return $stmt->fetchAll();
    }

    public function listForRouting(bool $includeWormholes = false): array
    {
        $selectFields = $this->systemSelectFields();
        $sql = "SELECT {$selectFields} FROM systems";
        if (!$includeWormholes) {
            $sql .= ' WHERE is_normal_universe = 1 AND is_wormhole = 0';
        }
        $stmt = $this->connection->pdo()->query($sql);
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
        $securityExpr = 'ROUND(COALESCE(security_raw, security), 1)';
        return "id, name, {$securityExpr} AS security, security_raw, {$securityExpr} AS security_nav, region_id, constellation_id, is_wormhole, is_normal_universe, has_npc_station, npc_station_count, system_size_au, x, y, z";
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
