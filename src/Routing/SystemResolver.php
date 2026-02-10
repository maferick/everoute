<?php

declare(strict_types=1);

namespace Everoute\Routing;

use Everoute\Security\Logger;
use Everoute\Universe\SystemRepository;

final class SystemResolver
{
    public function __construct(private SystemRepository $systemsRepo, private Logger $logger)
    {
    }

    public function resolveSystem(string|int $input): ?array
    {
        if (is_int($input) || ctype_digit((string) $input)) {
            $sql = 'SELECT ... FROM systems WHERE id = :id LIMIT 1';
            $row = $this->systemsRepo->getSystemById((int) $input);
            $this->logResolution('id', (string) $input, $sql, $row !== null, null);

            return $row;
        }

        $name = trim((string) $input);
        if ($name === '') {
            return null;
        }

        $exactSql = 'SELECT ... FROM systems WHERE name = :name LIMIT 1';
        $exact = $this->systemsRepo->getSystemByNameExact($name);
        $this->logResolution('name', $name, $exactSql, $exact !== null, 'exact');
        if ($exact !== null) {
            return $exact;
        }

        $ciSql = 'SELECT ... FROM systems WHERE LOWER(name) = LOWER(:name) LIMIT 1';
        $ci = $this->systemsRepo->getSystemByNameCI($name);
        $this->logResolution('name', $name, $ciSql, $ci !== null, 'ci');

        return $ci;
    }

    public function getSystemById(int $id): ?array
    {
        return $this->resolveSystem($id);
    }

    public function getSystemByNameExact(string $name): ?array
    {
        return $this->systemsRepo->getSystemByNameExact($name);
    }

    public function getSystemByNameCI(string $name): ?array
    {
        return $this->systemsRepo->getSystemByNameCI($name);
    }

    private function logResolution(string $inputType, string $input, string $query, bool $resolved, ?string $matchMode): void
    {
        $pdo = $this->systemsRepo->connection()->pdo();
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $schema = null;
        if ($driver === 'mysql') {
            $schemaValue = $pdo->query('SELECT DATABASE()')->fetchColumn();
            $schema = is_string($schemaValue) ? $schemaValue : null;
        }
        $this->logger->debug('System resolution query executed', [
            'input_type' => $inputType,
            'input' => $input,
            'query' => $query,
            'database' => $schema,
            'match_mode' => $matchMode,
            'resolved' => $resolved,
        ]);
    }
}
