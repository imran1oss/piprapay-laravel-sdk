<?php

namespace Imran1oss\PipraPay\Support;

final class PaymentValue
{
    public static function currency(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtoupper(trim($value, " \t\r\n"));

        return preg_match('/^[A-Z0-9]{3,10}$/D', $value) === 1 ? $value : null;
    }

    public static function amount(mixed $value, bool $allowZero = false): ?string
    {
        if (is_float($value)) {
            if (! is_finite($value)) {
                return null;
            }

            $value = self::expandFloat($value);
        } elseif (is_int($value)) {
            $value = (string) $value;
        } elseif (is_string($value)) {
            $value = trim($value, " \t\r\n");
        } else {
            return null;
        }

        if (preg_match('/^(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)$/D', $value) !== 1) {
            return null;
        }

        return $allowZero || strpbrk($value, '123456789') !== false ? $value : null;
    }

    private static function expandFloat(float $value): string
    {
        $decimal = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);

        if (preg_match('/^(-?)([0-9]+)(?:\.([0-9]+))?[eE]([+-]?[0-9]+)$/D', $decimal, $parts) === 1) {
            $digits = $parts[2].($parts[3] ?? '');
            $point = strlen($parts[2]) + (int) $parts[4];

            $decimal = match (true) {
                $point <= 0 => '0.'.str_repeat('0', -$point).$digits,
                $point >= strlen($digits) => $digits.str_repeat('0', $point - strlen($digits)),
                default => substr($digits, 0, $point).'.'.substr($digits, $point),
            };
            $decimal = $parts[1].$decimal;
        }

        return str_contains($decimal, '.') ? rtrim(rtrim($decimal, '0'), '.') : $decimal;
    }
}
