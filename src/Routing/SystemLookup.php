<?php

declare(strict_types=1);

namespace Everoute\Routing;

use Everoute\Security\Logger;
use Everoute\Universe\SystemRepository;

final class SystemLookup
{
    public function __construct(private SystemRepository $systemsRepo, private ?Logger $logger = null)
    {
    }

    public function resolveByNameOrId(string $value): ?array
    {
        $resolved = $this->systemsRepo->resolveSystem($value);
        $this->logResolution($value, $resolved);

        return $resolved;
    }

    private function logResolution(string $input, ?array $resolved): void
    {
        if ($this->logger === null) {
            return;
        }

        $pdo = $this->systemsRepo->connection()->pdo();
        $driver = (string) $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $schema = null;
        if ($driver === 'mysql') {
            $schemaValue = $pdo->query('SELECT DATABASE()')->fetchColumn();
            $schema = is_string($schemaValue) ? $schemaValue : null;
        }

        $this->logger->debug('System resolution query executed', [
            'input' => $input,
            'query' => ctype_digit($input)
                ? 'SELECT ... FROM systems WHERE id = :id LIMIT 1'
                : 'SELECT ... FROM systems WHERE name = :name OR LOWER(name)=LOWER(:name) LIMIT 1',
            'database' => $schema,
            'match_mode' => $resolved === null
                ? null
                : (((string) ($resolved['name'] ?? '')) === trim($input) ? 'exact' : 'ci_or_id'),
            'resolved' => $resolved !== null,
        ]);
    }
}
