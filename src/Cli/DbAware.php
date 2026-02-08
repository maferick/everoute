<?php

declare(strict_types=1);

namespace Everoute\Cli;

use Everoute\Config\Env;
use Everoute\DB\Connection;

trait DbAware
{
    protected function connection(): Connection
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            Env::get('DB_HOST', '127.0.0.1'),
            Env::int('DB_PORT', 3306),
            Env::get('DB_NAME', 'everoute')
        );

        return new Connection($dsn, Env::get('DB_USER', ''), Env::get('DB_PASS', ''));
    }
}
