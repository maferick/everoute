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
        ];

        return $aliases[$normalized] ?? $normalized;
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
