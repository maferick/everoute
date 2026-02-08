<?php

declare(strict_types=1);

namespace Everoute\Http;

use Everoute\Security\Logger;
use Throwable;

final class ErrorHandler
{
    public function __construct(private Logger $logger, private bool $debug)
    {
    }

    public function register(): void
    {
        set_exception_handler(function (Throwable $e): void {
            $this->logger->error('Unhandled exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            http_response_code(500);
            if ($this->debug) {
                echo 'Error: ' . $e->getMessage();
            } else {
                echo 'Internal Server Error';
            }
        });
    }
}
