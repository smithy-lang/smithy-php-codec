<?php

declare(strict_types=1);

namespace Smithy\Codec\Cbor;

/**
 * Convert bounded CBOR bignum payloads to decimal strings.
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
}
