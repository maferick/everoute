<?php

declare(strict_types=1);

namespace Everoute\Risk;

final class ZkillRedisQClient
{
    private const BASE_URL = 'https://zkillredisq.stream/listen.php';

    public function __construct(private string $userAgent)
    {
    }

    /**
     * @return array{status:int,body:?array}
     */
    public function poll(string $queueId, int $ttw): array
    {
        $url = sprintf('%s?queueID=%s&ttw=%d', self::BASE_URL, urlencode($queueId), $ttw);
        return $this->request($url, 0);
    }

    /**
     * @return array{status:int,body:?array}
     */
    private function request(string $url, int $redirects): array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => 15,
                'user_agent' => $this->userAgent,
                'ignore_errors' => true,
            ],
        ]);

        $raw = @file_get_contents($url, false, $context);
        $status = $this->parseStatus($http_response_header ?? []);
        $headers = $this->parseHeaders($http_response_header ?? []);

        if (in_array($status, [301, 302, 307, 308], true) && $redirects < 3 && isset($headers['location'])) {
            return $this->request($headers['location'], $redirects + 1);
        }

        if ($raw === false) {
            return ['status' => $status ?: 0, 'body' => null];
        }

        $data = json_decode($raw, true);
        return ['status' => $status ?: 200, 'body' => is_array($data) ? $data : null];
    }

    private function parseStatus(array $headers): int
    {
        if (!isset($headers[0])) {
            return 0;
        }

        if (preg_match('/HTTP\/\S+\s+(\d+)/', $headers[0], $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    private function parseHeaders(array $headers): array
    {
        $parsed = [];
        foreach ($headers as $header) {
            if (!str_contains($header, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $header, 2);
            $parsed[strtolower(trim($name))] = trim($value);
        }
        return $parsed;
    }
}
