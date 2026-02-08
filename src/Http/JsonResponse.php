<?php

declare(strict_types=1);

namespace Everoute\Http;

final class JsonResponse extends Response
{
    public function __construct(array $data, int $status = 200, array $headers = [])
    {
        $headers = array_merge([
            'Content-Type' => 'application/json; charset=utf-8',
        ], $headers);

        parent::__construct($status, $headers, json_encode($data, JSON_UNESCAPED_SLASHES));
    }
}
