<?php

declare(strict_types=1);

namespace Everoute\Security;

final class Logger
{
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    private function log(string $level, string $message, array $context): void
    {
        $payload = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'time' => gmdate('c'),
        ];
        error_log(json_encode($payload, JSON_UNESCAPED_SLASHES));
    }
}
