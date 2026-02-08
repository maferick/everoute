<?php

declare(strict_types=1);

namespace Everoute\Sde;

use RuntimeException;

final class SdeHttpClient
{
    public function __construct(private SdeConfig $config)
    {
    }

    public function download(string $url, string $targetPath): void
    {
        $attempts = 0;
        $lastError = null;
        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        while ($attempts < $this->config->retries) {
            $attempts++;
            $handle = fopen($targetPath, 'w');
            if ($handle === false) {
                throw new RuntimeException('Unable to write to ' . $targetPath);
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE => $handle,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => $this->config->timeout,
                CURLOPT_CONNECTTIMEOUT => min(15, $this->config->timeout),
                CURLOPT_USERAGENT => $this->config->userAgent,
                CURLOPT_FAILONERROR => true,
            ]);

            $success = curl_exec($ch);
            $error = $success ? null : curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            fclose($handle);

            if ($success && $status >= 200 && $status < 300 && filesize($targetPath) > 0) {
                return;
            }

            $lastError = $error ?: sprintf('HTTP status %d', $status);
            @unlink($targetPath);
            sleep(min(5, $attempts));
        }

        throw new RuntimeException(sprintf('Failed to download %s: %s', $url, $lastError ?? 'unknown error'));
    }
}
