<?php

declare(strict_types=1);

namespace Everoute\Sde;

use Generator;
use RuntimeException;

final class SdeJsonlReader
{
    /**
     * @return Generator<int, array<string, mixed>>
     */
    public static function read(string $path): Generator
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException('Unable to open JSONL file: ' . $path);
        }

        try {
            $lineNumber = 0;
            while (($line = fgets($handle)) !== false) {
                $lineNumber++;
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);
                if (!is_array($decoded)) {
                    throw new RuntimeException(sprintf('Invalid JSONL at %s:%d', $path, $lineNumber));
                }

                yield $decoded;
            }
        } finally {
            fclose($handle);
        }
    }
}
