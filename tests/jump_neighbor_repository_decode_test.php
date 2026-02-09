<?php

declare(strict_types=1);

use Everoute\Universe\JumpNeighborCodec;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    require_once __DIR__ . '/../src/Universe/JumpNeighborCodec.php';
}

$neighborIds = [1, 42, 65535, 123456789];

$encoded = JumpNeighborCodec::encodeNeighborIds($neighborIds);
$decoded = JumpNeighborCodec::decodeNeighborIds($encoded);
if ($decoded !== $neighborIds) {
    throw new RuntimeException('Neighbor id roundtrip failed.');
}

$neighborCount = count($neighborIds);
if (count($decoded) !== $neighborCount) {
    throw new RuntimeException('Neighbor id decoded count mismatch.');
}

echo "Jump neighbor codec test passed.\n";
