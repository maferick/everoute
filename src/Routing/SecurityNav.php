<?php

declare(strict_types=1);

namespace Everoute\Routing;

if (!class_exists(\Everoute\Universe\SecurityStatus::class)) {
    require_once __DIR__ . '/../Universe/SecurityStatus.php';
}

use Everoute\Universe\SecurityStatus;

final class SecurityNav
{
    public const HIGH_SEC_MIN = 0.5;
    public const LOW_SEC_MIN = 0.1;

    public static function getSecurityForRouting(array $system): float
    {
        return self::value($system);
    }

    public static function value(array $system): float
    {
        if (array_key_exists('security_display', $system) && $system['security_display'] !== null) {
            return SecurityStatus::round1dp((float) $system['security_display']);
        }

        if (array_key_exists('sec_class', $system) && is_string($system['sec_class'])) {
            $secClass = strtolower(trim((string) $system['sec_class']));
            if ($secClass === 'high') {
                return self::HIGH_SEC_MIN;
            }
            if ($secClass === 'low') {
                return self::LOW_SEC_MIN;
            }
            if ($secClass === 'null') {
                return 0.0;
            }
        }

        if (array_key_exists('security_nav', $system) && $system['security_nav'] !== null) {
            return SecurityStatus::round1dp((float) $system['security_nav']);
        }

        if (array_key_exists('security', $system) && $system['security'] !== null) {
            return SecurityStatus::round1dp((float) $system['security']);
        }

        $raw = array_key_exists('security_true', $system) && $system['security_true'] !== null
            ? (float) $system['security_true']
            : (array_key_exists('security_raw', $system) && $system['security_raw'] !== null
                ? (float) $system['security_raw']
                : 0.0);

        return SecurityStatus::navFromRaw($raw);
    }

    public static function debugComparison(array $system): array
    {
        $raw = array_key_exists('security_true', $system) && $system['security_true'] !== null
            ? (float) $system['security_true']
            : 0.0;
        if ($raw === 0.0) {
            $raw = array_key_exists('security_raw', $system) && $system['security_raw'] !== null
                ? (float) $system['security_raw']
                : (float) ($system['security'] ?? 0.0);
        }

        $fromDisplay = array_key_exists('security_display', $system) && $system['security_display'] !== null
            ? SecurityStatus::round1dp((float) $system['security_display'])
            : null;

        $fromSecClass = null;
        if (array_key_exists('sec_class', $system) && is_string($system['sec_class'])) {
            $secClass = strtolower(trim((string) $system['sec_class']));
            $fromSecClass = match ($secClass) {
                'high' => self::HIGH_SEC_MIN,
                'low' => self::LOW_SEC_MIN,
                'null' => 0.0,
                default => null,
            };
        }

        $fromRawRounded = SecurityStatus::navFromRaw($raw);
        $fromSecurity = array_key_exists('security', $system) && $system['security'] !== null
            ? SecurityStatus::round1dp((float) $system['security'])
            : null;
        $fromNav = array_key_exists('security_nav', $system) && $system['security_nav'] !== null
            ? SecurityStatus::round1dp((float) $system['security_nav'])
            : null;

        return [
            'security_true' => array_key_exists('security_true', $system) && $system['security_true'] !== null ? (float) $system['security_true'] : null,
            'security_raw' => $raw,
            'security_display' => $fromDisplay,
            'sec_class' => $system['sec_class'] ?? null,
            'sec_routing' => self::value($system),
            'sec_from_raw' => $fromRawRounded,
            'sec_from_security_display' => $fromDisplay,
            'sec_from_sec_class' => $fromSecClass,
            'sec_from_security' => $fromSecurity,
            'sec_nav' => $fromNav,
            'strategy' => 'security_display_or_sec_class_first_then_1dp_half_up',
            'matches_nav' => $fromNav === null ? null : abs(self::value($system) - $fromNav) < 0.0001,
        ];
    }

    public static function isIllegalHighsecForCapital(array $system, array $policy = []): bool
    {
        return self::value($system) >= self::HIGH_SEC_MIN;
    }

    public static function isHighsec(array $system): bool
    {
        return self::value($system) >= self::HIGH_SEC_MIN;
    }

    public static function isLowsec(array $system): bool
    {
        $security = self::value($system);
        return $security >= self::LOW_SEC_MIN && $security < self::HIGH_SEC_MIN;
    }

    public static function isNullsec(array $system): bool
    {
        return self::value($system) < self::LOW_SEC_MIN;
    }

    public static function spaceType(array $system): string
    {
        $security = self::value($system);
        if ($security >= self::HIGH_SEC_MIN) {
            return 'highsec';
        }
        if ($security >= self::LOW_SEC_MIN) {
            return 'lowsec';
        }

        return 'nullsec';
    }
}
