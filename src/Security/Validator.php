<?php

declare(strict_types=1);

namespace Everoute\Security;

final class Validator
{
    public function string(?string $value, int $min = 1, int $max = 128): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || strlen($value) < $min || strlen($value) > $max) {
            return null;
        }
        return $value;
    }

    public function bool($value, bool $default = false): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }
        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public function int($value, int $min, int $max, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $intVal = filter_var($value, FILTER_VALIDATE_INT);
        if ($intVal === false || $intVal < $min || $intVal > $max) {
            return $default;
        }
        return $intVal;
    }

    public function enum(string $value, array $allowed, string $default): string
    {
        return in_array($value, $allowed, true) ? $value : $default;
    }

    public function list(string $value, int $max = 50): array
    {
        $items = array_filter(array_map('trim', explode(',', $value)));
        return array_slice($items, 0, $max);
    }
}
