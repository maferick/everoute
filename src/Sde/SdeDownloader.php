<?php

declare(strict_types=1);

namespace Everoute\Sde;

use RuntimeException;
use ZipArchive;

final class SdeDownloader
{
    private const FILE_PATTERNS = [
        'systems' => [
            '/mapSolarSystems\.jsonl$/i',
            '/solarSystems\.jsonl$/i',
            '/solarsystems\.jsonl$/i',
        ],
        'stargates' => [
            '/mapStargates\.jsonl$/i',
            '/stargates\.jsonl$/i',
        ],
        'stations' => [
            '/staStations\.jsonl$/i',
            '/stations\.jsonl$/i',
        ],
    ];

    public function __construct(
        private SdeConfig $config,
        private SdeStorage $storage,
        private SdeHttpClient $http
    ) {
    }

    public function fetchLatestBuildNumber(): int
    {
        $this->storage->ensureBasePath();
        $latestPath = $this->storage->tempPath('latest.jsonl');
        $this->http->download($this->config->baseUrl . '/latest.jsonl', $latestPath);

        foreach (SdeJsonlReader::read($latestPath) as $record) {
            if (($record['key'] ?? $record['_key'] ?? null) !== 'sde') {
                continue;
            }

            $buildNumber = $this->extractBuildNumber($record);
            if ($buildNumber !== null) {
                return $buildNumber;
            }
        }

        throw new RuntimeException('Unable to find SDE build number in latest.jsonl');
    }

    public function downloadZip(int $buildNumber): string
    {
        $this->storage->ensureBasePath();
        $buildDir = $this->storage->buildDir($buildNumber);
        $this->storage->ensureDir($buildDir);

        $zipPath = $this->storage->zipPath($buildNumber);
        if (file_exists($zipPath) && filesize($zipPath) > 0) {
            return $zipPath;
        }

        $this->http->download($this->buildUrl($buildNumber), $zipPath);
        if (!file_exists($zipPath) || filesize($zipPath) === 0) {
            throw new RuntimeException('Downloaded zip is empty: ' . $zipPath);
        }

        return $zipPath;
    }

    /**
     * @return array{systems: string, stargates: string, stations: string}
     */
    public function extractRequiredFiles(string $zipPath, int $buildNumber): array
    {
        $extractDir = $this->storage->extractDir($buildNumber);
        $this->storage->ensureDir($extractDir);

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Unable to open zip file: ' . $zipPath);
        }

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name !== false) {
                $entries[] = $name;
            }
        }

        $found = [];
        foreach (self::FILE_PATTERNS as $key => $patterns) {
            foreach ($patterns as $pattern) {
                $match = $this->findEntry($entries, $pattern);
                if ($match !== null) {
                    $found[$key] = $match;
                    break;
                }
            }
            if (!isset($found[$key])) {
                $zip->close();
                throw new RuntimeException(sprintf('Required file for %s not found in zip.', $key));
            }
        }

        $paths = [];
        foreach ($found as $key => $entry) {
            $zip->extractTo($extractDir, [$entry]);
            $path = $extractDir . '/' . $entry;
            if (!file_exists($path)) {
                $zip->close();
                throw new RuntimeException('Extraction failed for ' . $entry);
            }
            $paths[$key] = $path;
        }

        $zip->close();

        return $paths;
    }

    public function buildUrl(int $buildNumber): string
    {
        return sprintf('%s/eve-online-static-data-%d-%s.zip', $this->config->baseUrl, $buildNumber, $this->config->variant);
    }

    private function extractBuildNumber(array $record): ?int
    {
        if (isset($record['buildNumber'])) {
            return (int) $record['buildNumber'];
        }
        if (isset($record['build_number'])) {
            return (int) $record['build_number'];
        }
        if (isset($record['value']['buildNumber'])) {
            return (int) $record['value']['buildNumber'];
        }
        if (isset($record['value']) && is_numeric($record['value'])) {
            return (int) $record['value'];
        }
        if (isset($record['data']['buildNumber'])) {
            return (int) $record['data']['buildNumber'];
        }

        return null;
    }

    private function findEntry(array $entries, string $pattern): ?string
    {
        foreach ($entries as $entry) {
            if (preg_match($pattern, $entry) === 1) {
                return $entry;
            }
        }

        return null;
    }
}
