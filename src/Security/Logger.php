<?php

declare(strict_types=1);

namespace Everoute\Security;

final class Logger
{
    private array $baseContext = [];
    private array $metrics = [];
    /** @var null|callable(string):void */
    private $writer;

    public function __construct(?callable $writer = null)
    {
        $this->writer = $writer;
    }

    public function setContext(array $context): void
    {
        $this->baseContext = $context;
    }

    public function addContext(array $context): void
    {
        $this->baseContext = array_merge($this->baseContext, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

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

    public function recordMetric(string $type, array $context = []): void
    {
        $this->metrics[] = array_merge(['type' => $type], $context);
    }

    public function flushMetrics(): void
    {
        if ($this->metrics === []) {
            return;
        }

        $this->debug('Route search metrics', [
            'metrics' => $this->metrics,
        ]);
        $this->metrics = [];
    }

    private function log(string $level, string $message, array $context): void
    {
        $payload = [
            'level' => $level,
            'message' => $message,
            'context' => array_merge($this->baseContext, $context),
            'time' => gmdate('c'),
        ];
        $line = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($this->writer !== null) {
            ($this->writer)($line);
            return;
        }
        error_log($line);
    }
}
