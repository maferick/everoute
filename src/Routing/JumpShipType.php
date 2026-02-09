<?php

declare(strict_types=1);

namespace Everoute\Routing;

final class JumpShipType
{
    public const CARRIER = 'carrier';
    public const DREAD = 'dread';
    public const FAX = 'fax';
    public const SUPER = 'super';
    public const TITAN = 'titan';
    public const JUMP_FREIGHTER = 'jump_freighter';
    public const BRIDGE_CHAIN_BLACK_OPS = 'black_ops';
    public const BRIDGE_CHAIN_COVOPS = 'covops';

    private const FATIGUE_BRIDGE_CHAIN_REDUCTION = 0.75;
    private const FATIGUE_INDUSTRIAL_REDUCTION = 0.90;

    private const INDUSTRIAL_HAULER_CLASSES = [
        'industrial',
        'hauler',
        'dst',
        'freighter',
        'jump_freighter',
        'capsule',
        'pod',
        'shuttle',
    ];

    public const ALL = [
        self::CARRIER,
        self::DREAD,
        self::FAX,
        self::SUPER,
        self::TITAN,
        self::JUMP_FREIGHTER,
    ];

    public const CAPITALS = [
        self::CARRIER,
        self::DREAD,
        self::FAX,
        self::SUPER,
        self::TITAN,
    ];

    public static function normalizeJumpShipType(string $input): string
    {
        $normalized = strtolower(trim($input));
        $normalized = str_replace(['-', ' '], '_', $normalized);
        $normalized = preg_replace('/_+/', '_', $normalized) ?? $normalized;
        $normalized = trim($normalized, '_');

        $aliases = [
            'carriers' => self::CARRIER,
            'dreadnought' => self::DREAD,
            'dreadnoughts' => self::DREAD,
            'force_auxiliary' => self::FAX,
            'forceauxiliary' => self::FAX,
            'force_auxiliaries' => self::FAX,
            'supercarrier' => self::SUPER,
            'super_carrier' => self::SUPER,
            'supercarriers' => self::SUPER,
            'super_carriers' => self::SUPER,
            'jf' => self::JUMP_FREIGHTER,
            'jumpfreighter' => self::JUMP_FREIGHTER,
            'jumpfreighters' => self::JUMP_FREIGHTER,
            'jump_freighter' => self::JUMP_FREIGHTER,
            'jump_freighters' => self::JUMP_FREIGHTER,
            'blackops' => self::BRIDGE_CHAIN_BLACK_OPS,
            'black_ops' => self::BRIDGE_CHAIN_BLACK_OPS,
            'black_ops_chain' => self::BRIDGE_CHAIN_BLACK_OPS,
            'covert_ops' => self::BRIDGE_CHAIN_COVOPS,
            'covertops' => self::BRIDGE_CHAIN_COVOPS,
            'covops' => self::BRIDGE_CHAIN_COVOPS,
            'cov_ops' => self::BRIDGE_CHAIN_COVOPS,
            'cov_ops_chain' => self::BRIDGE_CHAIN_COVOPS,
        ];

        return $aliases[$normalized] ?? $normalized;
    }

    public static function fatigueDistanceMultiplier(
        string $shipClass,
        string $jumpShipType,
        ?string $bridgeChainType = null
    ): float {
        $shipClass = self::normalizeJumpShipType($shipClass);
        $jumpShipType = self::normalizeJumpShipType($jumpShipType);
        $bridgeChain = $bridgeChainType === null ? '' : self::normalizeJumpShipType($bridgeChainType);

        $multiplier = 1.0;
        if (in_array($bridgeChain, [self::BRIDGE_CHAIN_BLACK_OPS, self::BRIDGE_CHAIN_COVOPS], true)) {
            $multiplier = min($multiplier, 1.0 - self::FATIGUE_BRIDGE_CHAIN_REDUCTION);
        }

        if (self::isIndustrialHaulerClass($shipClass) || self::isIndustrialHaulerClass($jumpShipType)) {
            $multiplier = min($multiplier, 1.0 - self::FATIGUE_INDUSTRIAL_REDUCTION);
        }

        return $multiplier;
    }

    private static function isIndustrialHaulerClass(string $shipClass): bool
    {
        return in_array($shipClass, self::INDUSTRIAL_HAULER_CLASSES, true);
    }

    public static function isAllowed(string $shipType): bool
    {
        return in_array($shipType, self::ALL, true);
    }

    public static function allowedList(): string
    {
        return implode(',', self::ALL);
    }
}
