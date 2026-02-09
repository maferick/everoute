<?php

declare(strict_types=1);

namespace Everoute\Universe;

final class JumpNeighborCodec
{
    /** @param int[] $ids */
    public static function encodeV1(array $ids): string
    {
        if ($ids === []) {
            return '';
        }
        $ids = array_map('intval', $ids);
        sort($ids);
        return pack('V*', ...$ids);
    }

    /** @return int[] */
    public static function decodeV1(string $blob, int $expectedCount): array
    {
        $expectedLength = $expectedCount * 4;
        if (strlen($blob) !== $expectedLength) {
            throw new \RuntimeException(sprintf(
                'Neighbor ids blob length mismatch (len=%d expected=%d).',
                strlen($blob),
                $expectedLength
            ));
        }
        if ($expectedLength === 0) {
            return [];
        }
        $unpacked = unpack('V*', $blob);
        if (!is_array($unpacked)) {
            return [];
        }
        return array_values(array_map('intval', $unpacked));
    }
}
