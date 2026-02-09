<?php

declare(strict_types=1);

use Everoute\Universe\JumpNeighborRepository;

$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    require_once __DIR__ . '/../src/Universe/JumpNeighborRepository.php';
}

$neighborIds = [1, 42, 65535, 123456789];

$compressed = JumpNeighborRepository::encodeNeighborIds($neighborIds, true);
$decodedCompressed = JumpNeighborRepository::decodeNeighborIds($compressed);
if ($decodedCompressed !== $neighborIds) {
    throw new RuntimeException('Compressed neighbor id roundtrip failed.');
}

$packed = JumpNeighborRepository::encodeNeighborIds($neighborIds, false);
$decodedPacked = JumpNeighborRepository::decodeNeighborIds($packed);
if ($decodedPacked !== $neighborIds) {
    throw new RuntimeException('Packed neighbor id roundtrip failed.');
}

echo "Jump neighbor repository decode test passed.\n";
