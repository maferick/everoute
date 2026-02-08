<?php

declare(strict_types=1);

namespace Everoute\Http;

class Response
{
    public int $status;
    public array $headers;
    public string $body;

    public function __construct(int $status = 200, array $headers = [], string $body = '')
    {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
