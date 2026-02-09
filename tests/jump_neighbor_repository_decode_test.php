<?php

declare(strict_types=1);

use Everoute\Universe\JumpNeighborCodec;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    require_once __DIR__ . '/../src/Universe/JumpNeighborCodec.php';
}

$neighborIds = [123456789, 1, 65535, 42];
$expected = [1, 42, 65535, 123456789];

$encoded = JumpNeighborCodec::encodeV1($neighborIds);
$decoded = JumpNeighborCodec::decodeV1($encoded, count($neighborIds));
if ($decoded !== $expected) {
    throw new RuntimeException('Neighbor id roundtrip failed.');
}

$neighborCount = count($neighborIds);
if (count($decoded) !== $neighborCount) {
    throw new RuntimeException('Neighbor id decoded count mismatch.');
}

$invalidBlob = $encoded . "\x00";
$threw = false;
try {
    JumpNeighborCodec::decodeV1($invalidBlob, $neighborCount);
} catch (RuntimeException $exception) {
    $threw = true;
}
if (!$threw) {
    throw new RuntimeException('Neighbor id invalid length did not throw.');
}

echo "Jump neighbor codec test passed.\n";
