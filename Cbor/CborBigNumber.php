<?php

declare(strict_types=1);

namespace Smithy\Codec\Cbor;

/**
 * Decimal-string arithmetic used by uncommon CBOR bignum payloads.
 */
final class CborBigNumber
{
    /**
     * Convert big-endian bytes into an unsigned decimal string.
     */
    public static function fromUnsignedBytes(string $bytes): string
    {
        $result = '0';
        $length = \strlen($bytes);

        for ($i = 0; $i < $length; $i++) {
            $result = self::multiplyBySmall($result, 256);
            $result = self::addSmall($result, \ord($bytes[$i]));
        }

        return $result;
    }

    /**
     * Add one to an unsigned decimal string.
     */
    public static function increment(string $value): string
    {
        $carry = 1;
        $result = '';
        for ($i = \strlen($value) - 1; $i >= 0; $i--) {
            $digit = (\ord($value[$i]) - 48) + $carry;
            if ($digit >= 10) {
                $digit -= 10;
                $carry = 1;
            } else {
                $carry = 0;
            }
            $result = \chr(48 + $digit) . $result;
        }
        if ($carry !== 0) {
            $result = '1' . $result;
        }

        return $result;
    }

    private static function multiplyBySmall(string $value, int $factor): string
    {
        if ($value === '0') {
            return '0';
        }

        $carry = 0;
        $result = '';
        for ($i = \strlen($value) - 1; $i >= 0; $i--) {
            $product = ((\ord($value[$i]) - 48) * $factor) + $carry;
            $result = \chr(48 + ($product % 10)) . $result;
            $carry = \intdiv($product, 10);
        }
        while ($carry !== 0) {
            $result = \chr(48 + ($carry % 10)) . $result;
            $carry = \intdiv($carry, 10);
        }

        return $result;
    }

    private static function addSmall(string $value, int $addend): string
    {
        $carry = $addend;
        $result = '';
        for ($i = \strlen($value) - 1; $i >= 0; $i--) {
            $sum = (\ord($value[$i]) - 48) + ($carry % 10);
            $carry = \intdiv($carry, 10);
            if ($sum >= 10) {
                $sum -= 10;
                $carry++;
            }
            $result = \chr(48 + $sum) . $result;
        }
        while ($carry !== 0) {
            $result = \chr(48 + ($carry % 10)) . $result;
            $carry = \intdiv($carry, 10);
        }

        return \ltrim($result, '0') ?: '0';
    }
}
