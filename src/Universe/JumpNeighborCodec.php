<?php

declare(strict_types=1);

namespace Everoute\Universe;

final class JumpNeighborCodec
{
    /** @param int[] $neighborIds */
    public static function encodeNeighborIds(array $neighborIds): string
    {
        if ($neighborIds === []) {
            return '';
        }
        $packed = pack('N*', ...array_map('intval', $neighborIds));
        $compressed = gzcompress($packed);
        return is_string($compressed) ? $compressed : $packed;
    }

    /** @return int[] */
    public static function decodeNeighborIds(string $payload): array
    {
        if ($payload === '') {
            return [];
        }
        $decompressed = @gzuncompress($payload);
        $binary = $decompressed !== false ? $decompressed : $payload;
        $unpacked = @unpack('N*', $binary);
        if (!is_array($unpacked)) {
            return [];
        }
        return array_values(array_map('intval', $unpacked));
    }
}
