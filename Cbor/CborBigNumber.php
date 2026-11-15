<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codec\Cbor;

/**
 * Convert between CBOR bignum payloads and decimal strings.
 */
final class CborBigNumber
{
    /**
     * Default maximum accepted CBOR bignum magnitude length.
     */
    public const DEFAULT_MAX_BYTES = 1024;

    private const INPUT_CHUNK_BYTES = 3;
    private const LIMB_BASE = 1_000_000_000;
    private const LIMB_DIGITS = 9;

    /**
     * Convert big-endian bytes into an unsigned decimal string.
     *
     * @param int<1, max> $maxBytes Maximum accepted payload length.
     *
     * @throws \InvalidArgumentException When the maximum is less than one.
     * @throws \LengthException When the payload exceeds the maximum.
     */
    public static function fromUnsignedBytes(
        string $bytes,
        int $maxBytes = self::DEFAULT_MAX_BYTES
    ): string
    {
        if ($maxBytes < 1) {
            throw new \InvalidArgumentException(
                'The maximum CBOR bignum length must be at least one byte.'
            );
        }

        $length = \strlen($bytes);
        if ($length > $maxBytes) {
            throw new \LengthException(
                "CBOR bignum payload exceeds {$maxBytes} bytes."
            );
        }
        if ($length === 0) {
            return '0';
        }

        $limbs = [0];
        $offset = 0;
        while ($offset < $length) {
            $chunkLength = \min(
                self::INPUT_CHUNK_BYTES,
                $length - $offset
            );
            $factor = 1;
            $carry = 0;
            for ($i = 0; $i < $chunkLength; $i++) {
                $factor *= 256;
                $carry = ($carry * 256) + \ord($bytes[$offset]);
                $offset++;
            }

            $limbCount = \count($limbs);
            for ($i = 0; $i < $limbCount; $i++) {
                $value = ($limbs[$i] * $factor) + $carry;
                $limbs[$i] = $value % self::LIMB_BASE;
                $carry = \intdiv($value, self::LIMB_BASE);
            }
            while ($carry !== 0) {
                $limbs[] = $carry % self::LIMB_BASE;
                $carry = \intdiv($carry, self::LIMB_BASE);
            }
        }

        $last = \count($limbs) - 1;
        $parts = [(string) $limbs[$last]];
        for ($i = $last - 1; $i >= 0; $i--) {
            $parts[] = \str_pad(
                (string) $limbs[$i],
                self::LIMB_DIGITS,
                '0',
                STR_PAD_LEFT
            );
        }

        return \implode('', $parts);
    }

    /**
     * Add one to an unsigned decimal string.
     */
    public static function increment(string $value): string
    {
        if ($value === '') {
            return '1';
        }

        for ($i = \strlen($value) - 1; $i >= 0; $i--) {
            if ($value[$i] !== '9') {
                $value[$i] = \chr(\ord($value[$i]) + 1);

                return $value;
            }
            $value[$i] = '0';
        }

        return '1' . $value;
    }

    /**
     * Subtract one from a positive unsigned decimal string.
     *
     * @throws \InvalidArgumentException When the value is zero.
     */
    public static function decrement(string $digits): string
    {
        $chars = \str_split($digits);
        for ($i = \count($chars) - 1; $i >= 0; $i--) {
            if ($chars[$i] !== '0') {
                $chars[$i] = \chr(\ord($chars[$i]) - 1);
                break;
            }
            $chars[$i] = '9';
        }
        if ($i < 0) {
            throw new \InvalidArgumentException(
                'Cannot decrement a zero unsigned decimal string.'
            );
        }

        $result = \ltrim(\implode('', $chars), '0');

        return $result === '' ? '0' : $result;
    }

    /**
     * Convert an unsigned decimal string to big-endian bytes.
     */
    public static function toUnsignedBytes(string $digits): string
    {
        if ($digits === '0') {
            return "\x00";
        }

        $bytes = '';
        while ($digits !== '0') {
            $quotient = '';
            $remainder = 0;
            $length = \strlen($digits);
            for ($i = 0; $i < $length; $i++) {
                $value = ($remainder * 10) + (\ord($digits[$i]) - 48);
                $digit = \intdiv($value, 256);
                if ($quotient !== '' || $digit !== 0) {
                    $quotient .= \chr(48 + $digit);
                }
                $remainder = $value - ($digit * 256);
            }

            $bytes = \chr($remainder) . $bytes;
            $digits = $quotient === '' ? '0' : $quotient;
        }

        return $bytes;
    }

    /**
     * Subtract a small nonnegative integer from a signed decimal string.
     *
     * @throws \InvalidArgumentException When the delta is negative.
     */
    public static function subtractSmall(string $value, int $delta): string
    {
        if ($delta < 0) {
            throw new \InvalidArgumentException(
                'The delta to subtract must be nonnegative.'
            );
        }
        if ($delta === 0) {
            return $value;
        }

        $negative = $value !== '' && $value[0] === '-';
        $digits = $negative ? \substr($value, 1) : $value;
        $digits = \ltrim($digits, '0');
        if ($digits === '') {
            return (string) -$delta;
        }

        $deltaString = (string) $delta;
        if ($negative) {
            return '-' . self::addUnsigned($digits, $deltaString);
        }

        $comparison = self::compareUnsigned($digits, $deltaString);
        if ($comparison === 0) {
            return '0';
        }
        if ($comparison > 0) {
            return self::subtractUnsigned($digits, $deltaString);
        }

        return '-' . self::subtractUnsigned($deltaString, $digits);
    }

    /**
     * Add two unsigned decimal strings.
     */
    private static function addUnsigned(string $left, string $right): string
    {
        $leftLength = \strlen($left);
        $rightLength = \strlen($right);
        $carry = 0;
        $result = '';

        for ($leftIndex = $leftLength - 1, $rightIndex = $rightLength - 1;
            $leftIndex >= 0 || $rightIndex >= 0 || $carry !== 0;
            $leftIndex--, $rightIndex--
        ) {
            $sum = $carry;
            if ($leftIndex >= 0) {
                $sum += \ord($left[$leftIndex]) - 48;
            }
            if ($rightIndex >= 0) {
                $sum += \ord($right[$rightIndex]) - 48;
            }
            $result = \chr(48 + ($sum % 10)) . $result;
            $carry = \intdiv($sum, 10);
        }

        return $result;
    }

    /**
     * Compare two unsigned decimal strings.
     */
    private static function compareUnsigned(string $left, string $right): int
    {
        $left = \ltrim($left, '0');
        $right = \ltrim($right, '0');
        $left = $left === '' ? '0' : $left;
        $right = $right === '' ? '0' : $right;

        $leftLength = \strlen($left);
        $rightLength = \strlen($right);
        if ($leftLength !== $rightLength) {
            return $leftLength <=> $rightLength;
        }

        return \strcmp($left, $right) <=> 0;
    }

    /**
     * Subtract one unsigned decimal string from another.
     */
    private static function subtractUnsigned(string $left, string $right): string
    {
        $leftLength = \strlen($left);
        $rightLength = \strlen($right);
        $borrow = 0;
        $result = '';

        for ($leftIndex = $leftLength - 1, $rightIndex = $rightLength - 1;
            $leftIndex >= 0;
            $leftIndex--, $rightIndex--
        ) {
            $digit = (\ord($left[$leftIndex]) - 48) - $borrow;
            $subtrahend = $rightIndex >= 0
                ? (\ord($right[$rightIndex]) - 48)
                : 0;
            if ($digit < $subtrahend) {
                $digit += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $result = \chr(48 + ($digit - $subtrahend)) . $result;
        }

        $result = \ltrim($result, '0');

        return $result === '' ? '0' : $result;
    }
}
