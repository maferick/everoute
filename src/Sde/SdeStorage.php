<?php

declare(strict_types=1);

namespace Everoute\Sde;

use RuntimeException;

final class SdeStorage
{
    public function __construct(private SdeConfig $config)
    {
    }

    public function ensureBasePath(): void
    {
        $this->ensureDir($this->config->storagePath);
    }

    public function buildDir(int $buildNumber): string
    {
        return $this->config->storagePath . '/build-' . $buildNumber;
    }

    public function zipPath(int $buildNumber): string
    {
        return $this->buildDir($buildNumber) . sprintf('/eve-online-static-data-%d-%s.zip', $buildNumber, $this->config->variant);
    }

    public function extractDir(int $buildNumber): string
    {
        return $this->buildDir($buildNumber) . '/extract';
    }

    public function tempPath(string $name): string
    {
        return $this->config->storagePath . '/tmp-' . $name;
    }

    public function cleanup(int $days): array
    {
        $this->ensureBasePath();
        $threshold = time() - ($days * 86400);
        $removedFiles = 0;
        $removedDirs = 0;

        $iterator = new \DirectoryIterator($this->config->storagePath);
        foreach ($iterator as $item) {
            if ($item->isDot()) {
                continue;
            }
            $path = $item->getPathname();
            $mtime = $item->getMTime();
            if ($mtime >= $threshold) {
                continue;
            }

            if ($item->isFile()) {
                if (@unlink($path)) {
                    $removedFiles++;
                }
                continue;
            }

            if ($item->isDir()) {
                $this->deleteDir($path);
                $removedDirs++;
            }
        }

        return ['files' => $removedFiles, 'dirs' => $removedDirs];
    }

    public function ensureDir(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create directory: ' . $path);
        }
    }

    private function deleteDir(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}
