<?php

declare(strict_types=1);

namespace Everoute\DB;

use PDO;
use PDOException;

final class Connection
{
    private PDO $pdo;

    public function __construct(string $dsn, string $user, string $pass)
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            throw new PDOException('Database connection failed: ' . $e->getMessage(), (int) $e->getCode());
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}
