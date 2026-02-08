<?php

declare(strict_types=1);

namespace Everoute\Risk;

use DateTimeImmutable;

final class ZkillFetcher
{
    private const BASE_URL = 'https://zkillboard.com/api/kills/systemID/';

    /**
     * @return array<int, array{kills_last_1h:int,kills_last_24h:int,pod_kills_last_1h:int,pod_kills_last_24h:int,last_updated_at:string}>
     */
    public function fetch(array $systemIds): array
    {
        $results = [];
        foreach ($systemIds as $systemId) {
            $kills = $this->fetchKillsForSystem((int) $systemId);
            $results[$systemId] = $kills;
        }
        return $results;
    }

    private function fetchKillsForSystem(int $systemId): array
    {
        $url = self::BASE_URL . $systemId . '/';
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'Everoute/1.0',
            ],
        ]);
        $raw = @file_get_contents($url, false, $context);
        $now = new DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($raw === false) {
            return [
                'kills_last_1h' => 0,
                'kills_last_24h' => 0,
                'pod_kills_last_1h' => 0,
                'pod_kills_last_24h' => 0,
                'last_updated_at' => $now->format('Y-m-d H:i:s'),
            ];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [
                'kills_last_1h' => 0,
                'kills_last_24h' => 0,
                'pod_kills_last_1h' => 0,
                'pod_kills_last_24h' => 0,
                'last_updated_at' => $now->format('Y-m-d H:i:s'),
            ];
        }

        $cutoff1h = $now->modify('-1 hour');
        $cutoff24h = $now->modify('-24 hours');
        $kills1h = 0;
        $kills24h = 0;
        $pod1h = 0;
        $pod24h = 0;

        foreach ($data as $kill) {
            $time = new DateTimeImmutable($kill['killmail_time'] ?? 'now', new \DateTimeZone('UTC'));
            if ($time >= $cutoff24h) {
                $kills24h++;
                if ($time >= $cutoff1h) {
                    $kills1h++;
                }
                $victim = $kill['victim'] ?? [];
                if (($victim['ship_type_id'] ?? 0) === 670) {
                    $pod24h++;
                    if ($time >= $cutoff1h) {
                        $pod1h++;
                    }
                }
            }
        }

        return [
            'kills_last_1h' => $kills1h,
            'kills_last_24h' => $kills24h,
            'pod_kills_last_1h' => $pod1h,
            'pod_kills_last_24h' => $pod24h,
            'last_updated_at' => $now->format('Y-m-d H:i:s'),
        ];
    }
}
