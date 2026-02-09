<?php

declare(strict_types=1);

namespace Everoute\Risk;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Everoute\Config\Env;

final class EsiSystemKillsClient
{
    private string $baseUrl;
    private string $tenant;
    private string $compatDate;
    private string $userAgent;

    public function __construct()
    {
        $this->baseUrl = rtrim(Env::get('ESI_BASE_URL', 'https://esi.evetech.net') ?? '', '/');
        $this->tenant = Env::get('ESI_TENANT', 'tranquility') ?? 'tranquility';
        $this->compatDate = Env::get('ESI_COMPAT_DATE', '2025-12-16') ?? '2025-12-16';
        $this->userAgent = Env::get('ESI_USER_AGENT', 'Everoute/1.0') ?? 'Everoute/1.0';
    }

    /**
     * @return array{status:int,data:?array,etag:?string,last_modified:?DateTimeImmutable}
     */
    public function fetchSystemKills(?string $etag, ?DateTimeInterface $lastModified): array
    {
        $url = $this->baseUrl . '/universe/system_kills/';
        $headers = [
            'Accept: application/json',
            'X-Compatibility-Date: ' . $this->compatDate,
            'X-Tenant: ' . $this->tenant,
            'User-Agent: ' . $this->userAgent,
        ];

        if ($etag) {
            $headers[] = 'If-None-Match: ' . $etag;
        }
        if ($lastModified) {
            $headers[] = 'If-Modified-Since: ' . $this->formatHttpDate($lastModified);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($response === false) {
            return [
                'status' => 0,
                'data' => null,
                'etag' => null,
                'last_modified' => null,
            ];
        }

        $rawHeaders = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);
        $parsedHeaders = $this->parseHeaders($rawHeaders);
        $etagValue = $parsedHeaders['etag'] ?? null;
        $lastModifiedValue = isset($parsedHeaders['last-modified'])
            ? DateTimeImmutable::createFromFormat(DateTimeInterface::RFC7231, $parsedHeaders['last-modified'], new DateTimeZone('UTC'))
            : null;

        $data = null;
        if ($status === 200) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        return [
            'status' => $status,
            'data' => $data,
            'etag' => $etagValue,
            'last_modified' => $lastModifiedValue ?: null,
        ];
    }

    private function formatHttpDate(DateTimeInterface $date): string
    {
        return gmdate('D, d M Y H:i:s', $date->getTimestamp()) . ' GMT';
    }

    /**
     * @return array<string, string>
     */
    private function parseHeaders(string $rawHeaders): array
    {
        $headers = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
        return $headers;
    }
}
