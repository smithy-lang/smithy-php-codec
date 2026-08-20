<?php

declare(strict_types=1);

namespace Smithy\Codec\Cbor;

use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\SerializableStructInterface;
use Smithy\Core\Serde\ShapeSerializerInterface;
use Smithy\Core\Serde\ShapeType;
use Smithy\Core\Serde\TimestampFormat;
use Smithy\Core\Shape\BigDecimal;
use Smithy\Core\Shape\BigInteger;
use Smithy\Core\Shape\Document\DocumentInterface;
use Smithy\Core\Shape\EnumInterface;
use Smithy\Core\Shape\UnionInterface;

/**
 * Serialize Smithy shapes into definite-length CBOR bytes.
 */
final class CborSerializer implements ShapeSerializerInterface
{
    private const TOKEN_FALSE = "\xF4";
    private const TOKEN_TRUE = "\xF5";
    private const TOKEN_NULL = "\xF6";
    private const TOKEN_TAG_EPOCH = "\xC1";
    private const TOKEN_TAG_POS_BIGINT = "\xC2";
    private const TOKEN_TAG_NEG_BIGINT = "\xC3";
    private const TOKEN_TAG_DECIMAL = "\xC4";
    private const TOKEN_FLOAT32 = "\xFA";
    private const TOKEN_FLOAT64 = "\xFB";

    /**
     * Common small integer encodings.
     *
     * These cover the highest-frequency scalar tokens and save the branchy
     * length-selection path for the smallest values.
     */
    private const POS_INT_CACHE = [
        0 => "\x00", 1 => "\x01", 2 => "\x02", 3 => "\x03",
        4 => "\x04", 5 => "\x05", 6 => "\x06", 7 => "\x07",
        8 => "\x08", 9 => "\x09", 10 => "\x0A", 11 => "\x0B",
        12 => "\x0C", 13 => "\x0D", 14 => "\x0E", 15 => "\x0F",
        16 => "\x10", 17 => "\x11", 18 => "\x12", 19 => "\x13",
        20 => "\x14", 21 => "\x15", 22 => "\x16", 23 => "\x17",
        24 => "\x18\x18", 25 => "\x18\x19", 26 => "\x18\x1A",
        32 => "\x18\x20", 64 => "\x18\x40", 100 => "\x18\x64",
        255 => "\x18\xFF", 256 => "\x19\x01\x00"
    ];

    private const NEG_INT_CACHE = [
        -1 => "\x20", -2 => "\x21", -3 => "\x22", -4 => "\x23",
        -5 => "\x24", -10 => "\x29", -20 => "\x33", -24 => "\x37",
        -25 => "\x38\x18", -100 => "\x38\x63"
    ];

    private readonly CborCodecSettings $settings;
    private readonly bool $honorTimestampFormatTrait;
    private string $buffer = '';
    /** @var list<string> */
    private array $bufferStack = [];
    /** @var list<array<int, string>|null> */
    private array $fieldNameTableStack = [];
    /** @var list<int> */
    private array $structMemberCountStack = [];
    private int $currentStructMemberCount = -1;
    /** @var array<int, string>|null */
    private ?array $currentFieldNameTable = null;

    /**
     * @param CborCodecSettings|null $settings Codec behavior overrides.
     */
    public function __construct(?CborCodecSettings $settings = null)
    {
        $this->settings = $settings ?? new CborCodecSettings();
        $this->honorTimestampFormatTrait =
            $this->settings->honorTimestampFormatTrait;
    }

    /**
     * Serialize a boolean value.
     *
     * @return void
     */
    public function serializeBoolean(Schema $schema, bool $value, int $idx): void
    {
        $table = $this->currentFieldNameTable;
        if ($table !== null) {
            $this->buffer .= $table[$idx]
                ?? $this->resolveMemberNameBytes($schema);
            if ($this->currentStructMemberCount !== -1) {
                $this->currentStructMemberCount++;
            }
        }

        $this->buffer .= $value ? self::TOKEN_TRUE : self::TOKEN_FALSE;
    }

    /**
     * Serialize an integer value.
     *
     * @return void
     */
    public function serializeInteger(Schema $schema, int $value, int $idx): void
    {
        $table = $this->currentFieldNameTable;
        if ($table !== null) {
            $this->buffer .= $table[$idx]
                ?? $this->resolveMemberNameBytes($schema);
            if ($this->currentStructMemberCount !== -1) {
                $this->currentStructMemberCount++;
            }
        }

        $this->writeIntegerValue($value);
    }

    /**
     * Serialize a floating-point value.
     *
     * @return void
     */
    public function serializeFloat(Schema $schema, float $value, int $idx): void
    {
        $table = $this->currentFieldNameTable;
        if ($table !== null) {
            $this->buffer .= $table[$idx]
                ?? $this->resolveMemberNameBytes($schema);
            if ($this->currentStructMemberCount !== -1) {
                $this->currentStructMemberCount++;
            }
        }

        switch ($schema->type) {
            case ShapeType::Float_->value:
                $this->buffer .= self::TOKEN_FLOAT32;
                $this->buffer .= \pack('G', $value);

                return;

            default:
                $this->buffer .= self::TOKEN_FLOAT64;
                $this->buffer .= \pack('E', $value);

                return;
        }
    }

    /**
     * Serialize a BigInteger value.
     *
     * @return void
     */
    public function serializeBigInteger(Schema $schema, BigInteger $value, int $idx): void
    {
        $table = $this->currentFieldNameTable;
        if ($table !== null) {
            $this->buffer .= $table[$idx]
                ?? $this->resolveMemberNameBytes($schema);
            if ($this->currentStructMemberCount !== -1) {
                $this->currentStructMemberCount++;
            }
        }

        $value = $value->value;
        if ($this->fitsSignedInt64($value)) {
            $this->writeIntegerValue((int) $value);

            return;
        }

        $negative = $value[0] === '-';
        $digits = $negative ? \substr($value, 1) : $value;
        $digits = \ltrim($digits, '0');
        if ($digits === '') {
            $this->buffer .= "\x00";

            return;
        }

        if ($negative) {
            $this->buffer .= self::TOKEN_TAG_NEG_BIGINT;
            $digits = $this->decrementUnsignedDecimalString($digits);
        } else {
            $this->buffer .= self::TOKEN_TAG_POS_BIGINT;
        }

        $value = $this->unsignedDecimalStringToBytes($digits);
        $length = \strlen($value);
        if ($length < 24) {
            $this->buffer .= \chr(0x40 | $length) . $value;

            return;
        }

        switch (true) {
            case $length <= 0xFF:
                $this->buffer .= "\x58" . \chr($length) . $value;

                return;

            case $length <= 0xFFFF:
                $this->buffer .= "\x59" . \pack('n', $length) . $value;

                return;

            case $length <= 0xFFFF_FFFF:
                $this->buffer .= "\x5A" . \pack('N', $length) . $value;

                return;
        }

        $high = ($length >> 32) & 0xFFFF_FFFF;
        $low = $length & 0xFFFF_FFFF;
        $this->buffer .= "\x5B" . \pack('NN', $high, $low) . $value;
    }

    /**
     * Serialize a BigDecimal value.
     *
     * @return void
     */
    public function serializeBigDecimal(Schema $schema, BigDecimal $value, int $idx): void
    {
        $table = $this->currentFieldNameTable;
        if ($table !== null) {
            $this->buffer .= $table[$idx]
                ?? $this->resolveMemberNameBytes($schema);
            if ($this->currentStructMemberCount !== -1) {
                $this->currentStructMemberCount++;
            }
        }

        $value = $value->value;
        $negative = $value[0] === '-';
        $offset = $negative ? 1 : 0;

        $exponentPos = \strpos($value, 'e', $offset);
        if ($exponentPos === false) {
            $exponentPos = \strpos($value, 'E', $offset);
        }

        if ($exponentPos === false) {
            $significand = $negative ? \substr($value, 1) : $value;
            $explicitExponent = '0';
        } else {
            $significand = \substr($value, $offset, $exponentPos - $offset);
            $explicitExponent = \substr($value, $exponentPos + 1);
        }

        $dotPos = \strpos($significand, '.');
        if ($dotPos === false) {
            $fractionLength = 0;
            $mantissaDigits = $significand;
        } else {
            $fractionLength = \strlen($significand) - $dotPos - 1;
            $mantissaDigits = \substr($significand, 0, $dotPos)
                . \substr($significand, $dotPos + 1);
        }

        $mantissaDigits = \ltrim($mantissaDigits, '0');
        if ($mantissaDigits === '') {
            $mantissa = '0';
        } else {
            $mantissa = $negative ? '-' . $mantissaDigits : $mantissaDigits;
        }

        $adjustedExponent = $this->subtractSmallFromSignedDecimalString(
            $explicitExponent,
            $fractionLength
        );

        $this->buffer .= self::TOKEN_TAG_DECIMAL;
        $this->buffer .= "\x82";

        if ($this->fitsSignedInt64($adjustedExponent)) {
            $this->writeIntegerValue((int) $adjustedExponent);
        } else {
            $adjustedExponentNegative = $adjustedExponent[0] === '-';
            $adjustedExponentDigits = $adjustedExponentNegative
                ? \substr($adjustedExponent, 1)
                : $adjustedExponent;
            $adjustedExponentDigits = \ltrim($adjustedExponentDigits, '0');
            if ($adjustedExponentDigits === '') {
                $this->buffer .= "\x00";
            } else {
                if ($adjustedExponentNegative) {
                    $this->buffer .= self::TOKEN_TAG_NEG_BIGINT;
                    $adjustedExponentDigits =
                        $this->decrementUnsignedDecimalString(
                            $adjustedExponentDigits
                        );
                } else {
                    $this->buffer .= self::TOKEN_TAG_POS_BIGINT;
                }

                $adjustedExponentValue = $this->unsignedDecimalStringToBytes(
                    $adjustedExponentDigits
                );
                $adjustedExponentLength = \strlen($adjustedExponentValue);
                if ($adjustedExponentLength < 24) {
                    $this->buffer .= \chr(0x40 | $adjustedExponentLength)
                        . $adjustedExponentValue;
                } else {
                    switch (true) {
                        case $adjustedExponentLength <= 0xFF:
                            $this->buffer .= "\x58"
                                . \chr($adjustedExponentLength)
                                . $adjustedExponentValue;
                            break;

                        case $adjustedExponentLength <= 0xFFFF:
                            $this->buffer .= "\x59"
                                . \pack('n', $adjustedExponentLength)
                                . $adjustedExponentValue;
                            break;

                        case $adjustedExponentLength <= 0xFFFF_FFFF:
                            $this->buffer .= "\x5A"
                                . \pack('N', $adjustedExponentLength)
                                . $adjustedExponentValue;
                            break;

                        default:
                            $high = ($adjustedExponentLength >> 32)
                                & 0xFFFF_FFFF;
                            $low = $adjustedExponentLength & 0xFFFF_FFFF;
                            $this->buffer .= "\x5B"
                                . \pack('NN', $high, $low)
                                . $adjustedExponentValue;
                            break;
                    }
                }
            }
        }

        if ($this->fitsSignedInt64($mantissa)) {
            $this->writeIntegerValue((int) $mantissa);

            return;
        }

        $mantissaNegative = $mantissa[0] === '-';
        $mantissaDigits = $mantissaNegative ? \substr($mantissa, 1) : $mantissa;
        $mantissaDigits = \ltrim($mantissaDigits, '0');
        if ($mantissaDigits === '') {
            $this->buffer .= "\x00";

            return;
        }

        if ($mantissaNegative) {
            $this->buffer .= self::TOKEN_TAG_NEG_BIGINT;
            $mantissaDigits =
                $this->decrementUnsignedDecimalString($mantissaDigits);
        } else {
            $this->buffer .= self::TOKEN_TAG_POS_BIGINT;
        }

        $mantissaValue = $this->unsignedDecimalStringToBytes($mantissaDigits);
        $mantissaLength = \strlen($mantissaValue);
        if ($mantissaLength < 24) {
            $this->buffer .= \chr(0x40 | $mantissaLength) . $mantissaValue;

            return;
        }

        switch (true) {
            case $mantissaLength <= 0xFF:
                $this->buffer .= "\x58"
                    . \chr($mantissaLength)
                    . $mantissaValue;

                return;

            case $mantissaLength <= 0xFFFF:
                $this->buffer .= "\x59"
                    . \pack('n', $mantissaLength)
                    . $mantissaValue;

                return;

            case $mantissaLength <= 0xFFFF_FFFF:
                $this->buffer .= "\x5A"
                    . \pack('N', $mantissaLength)
                    . $mantissaValue;

                return;
        }

        $high = ($mantissaLength >> 32) & 0xFFFF_FFFF;
        $low = $mantissaLength & 0xFFFF_FFFF;
        $this->buffer .= "\x5B" . \pack('NN', $high, $low) . $mantissaValue;
    }

    /**
     * Serialize a string value.
     *
     * @return void
     */
    public function serializeString(Schema $schema, string $value, int $idx): void
    {
        $table = $this->currentFieldNameTable;
        if ($table !== null) {
            $this->buffer .= $table[$idx]
                ?? $this->resolveMemberNameBytes($schema);
            if ($this->currentStructMemberCount !== -1) {
                $this->currentStructMemberCount++;
            }
        }

        $this->writeBytesWithMajor(0x60, $value);
    }

    /**
     * Serialize a blob value.
     *
     * @return void
     */
    public function serializeBlob(Schema $schema, string $value, int $idx): void
    {
        $table = $this->currentFieldNameTable;
        if ($table !== null) {
            $this->buffer .= $table[$idx]
                ?? $this->resolveMemberNameBytes($schema);
            if ($this->currentStructMemberCount !== -1) {
                $this->currentStructMemberCount++;
            }
        }

        $this->writeBytesWithMajor(0x40, $value);
    }

    /**
     * Serialize a timestamp value.
     *
     * @return void
     */
    public function serializeTimestamp(
        Schema $schema,
        float $value,
        int $idx,
        ?TimestampFormat $format = null
    ): void
    {
        $table = $this->currentFieldNameTable;
        if ($table !== null) {
            $this->buffer .= $table[$idx]
                ?? $this->resolveMemberNameBytes($schema);
            if ($this->currentStructMemberCount !== -1) {
                $this->currentStructMemberCount++;
            }
        }

        $resolved = ($this->honorTimestampFormatTrait
            ? ($format ?? TimestampFormat::fromTraitString(
                $schema->getTrait(Schema::T_TIMESTAMP_FORMAT)
            ))
            : null
        ) ?? $this->settings->defaultTimestampFormat;
        if ($resolved !== TimestampFormat::EpochSeconds) {
            $this->writeBytesWithMajor(0x60, $resolved->format($value));

            return;
        }

        $this->buffer .= self::TOKEN_TAG_EPOCH;

        $integral = (int) $value;
        if ($value == $integral
            && $value <= PHP_INT_MAX
            && $value >= PHP_INT_MIN
        ) {
            $this->writeIntegerValue($integral);

            return;
        }

        $this->buffer .= self::TOKEN_FLOAT64;
        $this->buffer .= \pack('E', $value);
    }

    /**
     * Serialize a list value.
     *
     * @return void
     */
    public function serializeList(
        Schema $schema,
        array $list,
        int $idx
    ): void
    {
        $table = $this->currentFieldNameTable;
        if ($table !== null) {
            $this->buffer .= $table[$idx]
                ?? $this->resolveMemberNameBytes($schema);
            if ($this->currentStructMemberCount !== -1) {
                $this->currentStructMemberCount++;
            }
        }

        static $extKey = null;
        $extKey ??= CborSchemaExtensionProvider::key();
        /** @var CborSchemaExtension|null $info */
        $info = $schema->getExtension($extKey);
        $this->writeListValue($schema, $list, $info);
    }

    /**
     * Serialize a map value.
     *
     * @return void
     */
    public function serializeMap(Schema $schema, array $map, int $idx): void
    {
        $table = $this->currentFieldNameTable;
        if ($table !== null) {
            $this->buffer .= $table[$idx]
                ?? $this->resolveMemberNameBytes($schema);
            if ($this->currentStructMemberCount !== -1) {
                $this->currentStructMemberCount++;
            }
        }

        static $extKey = null;
        $extKey ??= CborSchemaExtensionProvider::key();
        /** @var CborSchemaExtension|null $info */
        $info = $schema->getExtension($extKey);
        $this->writeMapValue($schema, $map, $info);
    }

    /**
     * Serialize a structure value.
     *
     * @return void
     */
    public function serializeStruct(
        Schema $schema,
        SerializableStructInterface $struct,
        int $idx
    ): void
    {
        $table = $this->currentFieldNameTable;
        if ($table !== null) {
            $this->buffer .= $table[$idx]
                ?? $this->resolveMemberNameBytes($schema);
            if ($this->currentStructMemberCount !== -1) {
                $this->currentStructMemberCount++;
            }
        }

        $this->beginStructFrame($struct->getSchema());
        $struct->serializeMembers($this);
        $this->endStructFrame();
    }

    /**
     * Begin a structure frame.
     *
     * @return void
     */
    public function beginStruct(Schema $schema, int $idx): void
    {
        $table = $this->currentFieldNameTable;
        if ($table !== null) {
            $this->buffer .= $table[$idx]
                ?? $this->resolveMemberNameBytes($schema);
            if ($this->currentStructMemberCount !== -1) {
                $this->currentStructMemberCount++;
            }
        }

        $this->beginStructFrame($schema);
    }

    /**
     * End the active structure frame.
     *
     * @return void
     */
    public function endStruct(): void
    {
        $this->endStructFrame();
    }

    /**
     * Serialize a union value.
     *
     * @return void
     */
    public function serializeUnion(Schema $schema, mixed $union, int $idx): void
    {
        $table = $this->currentFieldNameTable;
        if ($table !== null) {
            $this->buffer .= $table[$idx]
                ?? $this->resolveMemberNameBytes($schema);
            if ($this->currentStructMemberCount !== -1) {
                $this->currentStructMemberCount++;
            }
        }

        static $extKey = null;
        $extKey ??= CborSchemaExtensionProvider::key();
        /** @var CborSchemaExtension|null $info */
        $info = $schema->getExtension($extKey);
        $this->writeUnionValue($schema, $union, $info);
    }

    /**
     * Reject document values for rpcv2-cbor.
     *
     * @return void
     */
    public function serializeDocument(
        Schema $schema,
        ?DocumentInterface $value,
        int $idx
    ): void
    {
        if ($value === null) {

            return;
        }

        throw new \LogicException(
            'rpcv2-cbor does not support Smithy document shapes.'
        );
    }

    /**
     * Reset the serializer buffer and frame state.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->buffer = '';
        $this->bufferStack = [];
        $this->fieldNameTableStack = [];
        $this->structMemberCountStack = [];
        $this->currentStructMemberCount = -1;
        $this->currentFieldNameTable = null;
    }

    /**
     * Return the encoded CBOR payload.
     *
     * @return string
     */
    public function flush(): string
    {
        return $this->buffer;
    }

    /**
     * Serialize a nested value without member-name handling.
     *
     * @return void
     */
    private function writeValue(Schema $schema, mixed $value): void
    {
        switch ($schema->type) {
            case ShapeType::Boolean->value:
                $this->buffer .= $value ? self::TOKEN_TRUE : self::TOKEN_FALSE;

                return;

            case ShapeType::Byte->value:
            case ShapeType::Short->value:
            case ShapeType::Integer->value:
            case ShapeType::Long->value:
                $this->writeIntegerValue($value);

                return;

            case ShapeType::Float_->value:
                $this->buffer .= self::TOKEN_FLOAT32;
                $this->buffer .= \pack('G', (float) $value);

                return;

            case ShapeType::Double->value:
                $this->buffer .= self::TOKEN_FLOAT64;
                $this->buffer .= \pack('E', (float) $value);

                return;

            case ShapeType::BigInteger->value:
                $value = $value instanceof BigInteger
                    ? $value->value
                    : (string) $value;
                if ($this->fitsSignedInt64($value)) {
                    $this->writeIntegerValue((int) $value);

                    return;
                }

                $negative = $value[0] === '-';
                $digits = $negative ? \substr($value, 1) : $value;
                $digits = \ltrim($digits, '0');
                if ($digits === '') {
                    $this->buffer .= "\x00";

                    return;
                }

                if ($negative) {
                    $this->buffer .= self::TOKEN_TAG_NEG_BIGINT;
                    $digits = $this->decrementUnsignedDecimalString($digits);
                } else {
                    $this->buffer .= self::TOKEN_TAG_POS_BIGINT;
                }

                $value = $this->unsignedDecimalStringToBytes($digits);
                $length = \strlen($value);
                if ($length < 24) {
                    $this->buffer .= \chr(0x40 | $length) . $value;

                    return;
                }

                switch (true) {
                    case $length <= 0xFF:
                        $this->buffer .= "\x58" . \chr($length) . $value;

                        return;

                    case $length <= 0xFFFF:
                        $this->buffer .= "\x59" . \pack('n', $length) . $value;

                        return;

                    case $length <= 0xFFFF_FFFF:
                        $this->buffer .= "\x5A" . \pack('N', $length) . $value;

                        return;
                }

                $high = ($length >> 32) & 0xFFFF_FFFF;
                $low = $length & 0xFFFF_FFFF;
                $this->buffer .= "\x5B" . \pack('NN', $high, $low) . $value;

                return;

            case ShapeType::BigDecimal->value:
                $value = $value instanceof BigDecimal
                    ? $value->value
                    : (string) $value;
                $negative = $value[0] === '-';
                $offset = $negative ? 1 : 0;

                $exponentPos = \strpos($value, 'e', $offset);
                if ($exponentPos === false) {
                    $exponentPos = \strpos($value, 'E', $offset);
                }

                if ($exponentPos === false) {
                    $significand = $negative ? \substr($value, 1) : $value;
                    $explicitExponent = '0';
                } else {
                    $significand = \substr(
                        $value,
                        $offset,
                        $exponentPos - $offset
                    );
                    $explicitExponent = \substr($value, $exponentPos + 1);
                }

                $dotPos = \strpos($significand, '.');
                if ($dotPos === false) {
                    $fractionLength = 0;
                    $mantissaDigits = $significand;
                } else {
                    $fractionLength = \strlen($significand) - $dotPos - 1;
                    $mantissaDigits = \substr($significand, 0, $dotPos)
                        . \substr($significand, $dotPos + 1);
                }

                $mantissaDigits = \ltrim($mantissaDigits, '0');
                if ($mantissaDigits === '') {
                    $mantissa = '0';
                } else {
                    $mantissa = $negative
                        ? '-' . $mantissaDigits
                        : $mantissaDigits;
                }

                $adjustedExponent = $this->subtractSmallFromSignedDecimalString(
                    $explicitExponent,
                    $fractionLength
                );

                $this->buffer .= self::TOKEN_TAG_DECIMAL;
                $this->buffer .= "\x82";

                if ($this->fitsSignedInt64($adjustedExponent)) {
                    $this->writeIntegerValue((int) $adjustedExponent);
                } else {
                    $adjustedExponentNegative = $adjustedExponent[0] === '-';
                    $adjustedExponentDigits = $adjustedExponentNegative
                        ? \substr($adjustedExponent, 1)
                        : $adjustedExponent;
                    $adjustedExponentDigits = \ltrim(
                        $adjustedExponentDigits,
                        '0'
                    );
                    if ($adjustedExponentDigits === '') {
                        $this->buffer .= "\x00";
                    } else {
                        if ($adjustedExponentNegative) {
                            $this->buffer .= self::TOKEN_TAG_NEG_BIGINT;
                            $adjustedExponentDigits =
                                $this->decrementUnsignedDecimalString(
                                    $adjustedExponentDigits
                                );
                        } else {
                            $this->buffer .= self::TOKEN_TAG_POS_BIGINT;
                        }

                        $adjustedExponentValue =
                            $this->unsignedDecimalStringToBytes(
                                $adjustedExponentDigits
                            );
                        $adjustedExponentLength = \strlen(
                            $adjustedExponentValue
                        );
                        if ($adjustedExponentLength < 24) {
                            $this->buffer .= \chr(0x40 | $adjustedExponentLength)
                                . $adjustedExponentValue;
                        } else {
                            switch (true) {
                                case $adjustedExponentLength <= 0xFF:
                                    $this->buffer .= "\x58"
                                        . \chr($adjustedExponentLength)
                                        . $adjustedExponentValue;
                                    break;

                                case $adjustedExponentLength <= 0xFFFF:
                                    $this->buffer .= "\x59"
                                        . \pack('n', $adjustedExponentLength)
                                        . $adjustedExponentValue;
                                    break;

                                case $adjustedExponentLength <= 0xFFFF_FFFF:
                                    $this->buffer .= "\x5A"
                                        . \pack('N', $adjustedExponentLength)
                                        . $adjustedExponentValue;
                                    break;

                                default:
                                    $high = ($adjustedExponentLength >> 32)
                                        & 0xFFFF_FFFF;
                                    $low =
                                        $adjustedExponentLength & 0xFFFF_FFFF;
                                    $this->buffer .= "\x5B"
                                        . \pack('NN', $high, $low)
                                        . $adjustedExponentValue;
                                    break;
                            }
                        }
                    }
                }

                if ($this->fitsSignedInt64($mantissa)) {
                    $this->writeIntegerValue((int) $mantissa);

                    return;
                }

                $mantissaNegative = $mantissa[0] === '-';
                $mantissaDigits = $mantissaNegative
                    ? \substr($mantissa, 1)
                    : $mantissa;
                $mantissaDigits = \ltrim($mantissaDigits, '0');
                if ($mantissaDigits === '') {
                    $this->buffer .= "\x00";

                    return;
                }

                if ($mantissaNegative) {
                    $this->buffer .= self::TOKEN_TAG_NEG_BIGINT;
                    $mantissaDigits = $this->decrementUnsignedDecimalString(
                        $mantissaDigits
                    );
                } else {
                    $this->buffer .= self::TOKEN_TAG_POS_BIGINT;
                }

                $mantissaValue = $this->unsignedDecimalStringToBytes(
                    $mantissaDigits
                );
                $mantissaLength = \strlen($mantissaValue);
                if ($mantissaLength < 24) {
                    $this->buffer .= \chr(0x40 | $mantissaLength)
                        . $mantissaValue;

                    return;
                }

                switch (true) {
                    case $mantissaLength <= 0xFF:
                        $this->buffer .= "\x58"
                            . \chr($mantissaLength)
                            . $mantissaValue;

                        return;

                    case $mantissaLength <= 0xFFFF:
                        $this->buffer .= "\x59"
                            . \pack('n', $mantissaLength)
                            . $mantissaValue;

                        return;

                    case $mantissaLength <= 0xFFFF_FFFF:
                        $this->buffer .= "\x5A"
                            . \pack('N', $mantissaLength)
                            . $mantissaValue;

                        return;
                }

                $high = ($mantissaLength >> 32) & 0xFFFF_FFFF;
                $low = $mantissaLength & 0xFFFF_FFFF;
                $this->buffer .= "\x5B"
                    . \pack('NN', $high, $low)
                    . $mantissaValue;

                return;

            case ShapeType::String_->value:
            case ShapeType::Enum->value:
                $value = $value instanceof EnumInterface
                    ? (string) $value->getValue()
                    : (string) $value;
                $length = \strlen($value);
                if ($length < 24) {
                    $this->buffer .= \chr(0x60 | $length) . $value;

                    return;
                }

                switch (true) {
                    case $length <= 0xFF:
                        $this->buffer .= "\x78" . \chr($length) . $value;

                        return;

                    case $length <= 0xFFFF:
                        $this->buffer .= "\x79" . \pack('n', $length) . $value;

                        return;

                    case $length <= 0xFFFF_FFFF:
                        $this->buffer .= "\x7A" . \pack('N', $length) . $value;

                        return;
                }

                $high = ($length >> 32) & 0xFFFF_FFFF;
                $low = $length & 0xFFFF_FFFF;
                $this->buffer .= "\x7B" . \pack('NN', $high, $low) . $value;

                return;

            case ShapeType::Blob->value:
                $value = (string) $value;
                $length = \strlen($value);
                if ($length < 24) {
                    $this->buffer .= \chr(0x40 | $length) . $value;

                    return;
                }

                switch (true) {
                    case $length <= 0xFF:
                        $this->buffer .= "\x58" . \chr($length) . $value;

                        return;

                    case $length <= 0xFFFF:
                        $this->buffer .= "\x59" . \pack('n', $length) . $value;

                        return;

                    case $length <= 0xFFFF_FFFF:
                        $this->buffer .= "\x5A" . \pack('N', $length) . $value;

                        return;
                }

                $high = ($length >> 32) & 0xFFFF_FFFF;
                $low = $length & 0xFFFF_FFFF;
                $this->buffer .= "\x5B" . \pack('NN', $high, $low) . $value;

                return;

            case ShapeType::Timestamp->value:
                if ($value instanceof \DateTimeInterface) {
                    $value = (float) $value->format('U.u');
                }
                $value = (float) $value;
                $resolved = ($this->honorTimestampFormatTrait
                    ? TimestampFormat::fromTraitString(
                        $schema->getTrait(Schema::T_TIMESTAMP_FORMAT)
                    )
                    : null
                ) ?? $this->settings->defaultTimestampFormat;
                if ($resolved !== TimestampFormat::EpochSeconds) {
                    $this->writeBytesWithMajor(0x60, $resolved->format($value));

                    return;
                }

                $this->buffer .= self::TOKEN_TAG_EPOCH;
                $integral = (int) $value;
                if ($value == $integral
                    && $value <= PHP_INT_MAX
                    && $value >= PHP_INT_MIN
                ) {
                    $this->writeIntegerValue($integral);

                    return;
                }

                $this->buffer .= self::TOKEN_FLOAT64;
                $this->buffer .= \pack('E', $value);

                return;

            case ShapeType::List_->value:
                static $listExtKey = null;
                $listExtKey ??= CborSchemaExtensionProvider::key();
                /** @var CborSchemaExtension|null $listInfo */
                $listInfo = $schema->getExtension($listExtKey);
                $this->writeListValue($schema, $value, $listInfo);

                return;

            case ShapeType::Map->value:
                static $mapExtKey = null;
                $mapExtKey ??= CborSchemaExtensionProvider::key();
                /** @var CborSchemaExtension|null $mapInfo */
                $mapInfo = $schema->getExtension($mapExtKey);
                $this->writeMapValue($schema, $value, $mapInfo);

                return;

            case ShapeType::Structure->value:
                if (!$value instanceof SerializableStructInterface) {
                    throw new \InvalidArgumentException(
                        'Expected SerializableStructInterface for structure'
                        . ' value.'
                    );
                }
                $this->beginStructFrame($value->getSchema());
                $value->serializeMembers($this);
                $this->endStructFrame();

                return;

            case ShapeType::Union->value:
                static $unionExtKey = null;
                $unionExtKey ??= CborSchemaExtensionProvider::key();
                /** @var CborSchemaExtension|null $unionInfo */
                $unionInfo = $schema->getExtension($unionExtKey);
                $this->writeUnionValue($schema, $value, $unionInfo);

                return;

            case ShapeType::IntEnum->value:
                $this->writeIntegerValue(
                    $value instanceof EnumInterface
                        ? (int) $value->getValue()
                        : (int) $value
                );

                return;

            case ShapeType::Document->value:
                throw new \LogicException(
                    'rpcv2-cbor does not support Smithy document shapes.'
                );
        }

        throw new \InvalidArgumentException(
            'Unsupported CBOR shape type: ' . $schema->type
        );
    }

    /**
     * Serialize a list using precomputed member metadata.
     *
     * @return void
     */
    private function writeListValue(
        Schema $schema,
        array $list,
        ?CborSchemaExtension $info = null
    ): void
    {
        if ($info === null) {
            static $extKey = null;
            $extKey ??= CborSchemaExtensionProvider::key();
            /** @var CborSchemaExtension|null $info */
            $info = $schema->getExtension($extKey);
        }

        $memberSchema = $info?->listMemberSchema ?? $schema->listMember;
        $sparse = $info?->sparse ?? false;
        $this->writeArrayStart($this->countListItems($list, $sparse));
        if ($memberSchema === null) {

            return;
        }

        $memberType = $info?->listMemberType ?? $memberSchema->type;
        switch ($memberType) {
            case ShapeType::Union->value:
                $unionInfo = $info?->listMemberUnionInfo;
                foreach ($list as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $this->buffer .= self::TOKEN_NULL;
                        }
                        continue;
                    }
                    $this->writeUnionValue(
                        $memberSchema,
                        $item,
                        $unionInfo
                    );
                }

                return;

            case ShapeType::Boolean->value:
                foreach ($list as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $this->buffer .= self::TOKEN_NULL;
                        }
                        continue;
                    }
                    $this->buffer .= $item
                        ? self::TOKEN_TRUE
                        : self::TOKEN_FALSE;
                }

                return;

            case ShapeType::Byte->value:
            case ShapeType::Short->value:
            case ShapeType::Integer->value:
            case ShapeType::Long->value:
            case ShapeType::IntEnum->value:
                foreach ($list as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $this->buffer .= self::TOKEN_NULL;
                        }
                        continue;
                    }
                    $this->writeIntegerValue(
                        $item instanceof EnumInterface
                            ? (int) $item->getValue()
                            : (int) $item
                    );
                }

                return;

            case ShapeType::Float_->value:
                foreach ($list as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $this->buffer .= self::TOKEN_NULL;
                        }
                        continue;
                    }
                    $this->buffer .= self::TOKEN_FLOAT32
                        . \pack('G', (float) $item);
                }

                return;

            case ShapeType::Double->value:
                foreach ($list as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $this->buffer .= self::TOKEN_NULL;
                        }
                        continue;
                    }
                    $this->buffer .= self::TOKEN_FLOAT64
                        . \pack('E', (float) $item);
                }

                return;

            case ShapeType::String_->value:
            case ShapeType::Enum->value:
                foreach ($list as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $this->buffer .= self::TOKEN_NULL;
                        }
                        continue;
                    }
                    $this->writeBytesWithMajor(
                        0x60,
                        $item instanceof EnumInterface
                            ? (string) $item->getValue()
                            : (string) $item
                    );
                }

                return;

            case ShapeType::Blob->value:
                foreach ($list as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $this->buffer .= self::TOKEN_NULL;
                        }
                        continue;
                    }
                    $this->writeBytesWithMajor(0x40, (string) $item);
                }

                return;

            case ShapeType::Timestamp->value:
                $timestampFormat = $info?->listMemberTimestampFormat;
                foreach ($list as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $this->buffer .= self::TOKEN_NULL;
                        }
                        continue;
                    }
                    $this->writeTimestampValue($item, $timestampFormat);
                }

                return;

            case ShapeType::Structure->value:
                foreach ($list as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $this->buffer .= self::TOKEN_NULL;
                        }
                        continue;
                    }
                    if (!$item instanceof SerializableStructInterface) {
                        throw new \InvalidArgumentException(
                            'Expected SerializableStructInterface for '
                            . 'structure value.'
                        );
                    }
                    $this->beginStructFrame($item->getSchema());
                    $item->serializeMembers($this);
                    $this->endStructFrame();
                }

                return;

            case ShapeType::List_->value:
                static $listExtKey = null;
                $listExtKey ??= CborSchemaExtensionProvider::key();
                /** @var CborSchemaExtension|null $nestedListInfo */
                $nestedListInfo = $memberSchema->getExtension($listExtKey);
                foreach ($list as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $this->buffer .= self::TOKEN_NULL;
                        }
                        continue;
                    }
                    $this->writeListValue(
                        $memberSchema,
                        $item,
                        $nestedListInfo
                    );
                }

                return;

            case ShapeType::Map->value:
                static $mapExtKey = null;
                $mapExtKey ??= CborSchemaExtensionProvider::key();
                /** @var CborSchemaExtension|null $nestedMapInfo */
                $nestedMapInfo = $memberSchema->getExtension($mapExtKey);
                foreach ($list as $item) {
                    if ($item === null) {
                        if ($sparse) {
                            $this->buffer .= self::TOKEN_NULL;
                        }
                        continue;
                    }
                    $this->writeMapValue(
                        $memberSchema,
                        $item,
                        $nestedMapInfo
                    );
                }

                return;
        }

        foreach ($list as $item) {
            if ($item === null) {
                if ($sparse) {
                    $this->buffer .= self::TOKEN_NULL;
                }
                continue;
            }
            $this->writeValue($memberSchema, $item);
        }
    }

    /**
     * Serialize a map using precomputed value metadata.
     *
     * @return void
     */
    private function writeMapValue(
        Schema $schema,
        array $map,
        ?CborSchemaExtension $info = null
    ): void
    {
        if ($info === null) {
            static $extKey = null;
            $extKey ??= CborSchemaExtensionProvider::key();
            /** @var CborSchemaExtension|null $info */
            $info = $schema->getExtension($extKey);
        }

        $valueSchema = $info?->mapValueSchema ?? $schema->mapValue;
        $sparse = $info?->sparse ?? false;
        $this->writeMapStart($this->countMapEntries($map, $sparse));
        if ($valueSchema === null) {

            return;
        }

        $valueType = $info?->mapValueType ?? $valueSchema->type;
        switch ($valueType) {
            case ShapeType::Union->value:
                $unionInfo = $info?->mapValueUnionInfo;
                foreach ($map as $key => $value) {
                    if ($value === null && !$sparse) {
                        continue;
                    }
                    $this->writeBytesWithMajor(0x60, (string) $key);
                    if ($value === null) {
                        $this->buffer .= self::TOKEN_NULL;
                        continue;
                    }
                    $this->writeUnionValue(
                        $valueSchema,
                        $value,
                        $unionInfo
                    );
                }

                return;

            case ShapeType::Boolean->value:
                foreach ($map as $key => $value) {
                    if ($value === null && !$sparse) {
                        continue;
                    }
                    $this->writeBytesWithMajor(0x60, (string) $key);
                    if ($value === null) {
                        $this->buffer .= self::TOKEN_NULL;
                        continue;
                    }
                    $this->buffer .= $value
                        ? self::TOKEN_TRUE
                        : self::TOKEN_FALSE;
                }

                return;

            case ShapeType::Byte->value:
            case ShapeType::Short->value:
            case ShapeType::Integer->value:
            case ShapeType::Long->value:
            case ShapeType::IntEnum->value:
                foreach ($map as $key => $value) {
                    if ($value === null && !$sparse) {
                        continue;
                    }
                    $this->writeBytesWithMajor(0x60, (string) $key);
                    if ($value === null) {
                        $this->buffer .= self::TOKEN_NULL;
                        continue;
                    }
                    $this->writeIntegerValue(
                        $value instanceof EnumInterface
                            ? (int) $value->getValue()
                            : (int) $value
                    );
                }

                return;

            case ShapeType::Float_->value:
                foreach ($map as $key => $value) {
                    if ($value === null && !$sparse) {
                        continue;
                    }
                    $this->writeBytesWithMajor(0x60, (string) $key);
                    if ($value === null) {
                        $this->buffer .= self::TOKEN_NULL;
                        continue;
                    }
                    $this->buffer .= self::TOKEN_FLOAT32
                        . \pack('G', (float) $value);
                }

                return;

            case ShapeType::Double->value:
                foreach ($map as $key => $value) {
                    if ($value === null && !$sparse) {
                        continue;
                    }
                    $this->writeBytesWithMajor(0x60, (string) $key);
                    if ($value === null) {
                        $this->buffer .= self::TOKEN_NULL;
                        continue;
                    }
                    $this->buffer .= self::TOKEN_FLOAT64
                        . \pack('E', (float) $value);
                }

                return;

            case ShapeType::String_->value:
            case ShapeType::Enum->value:
                foreach ($map as $key => $value) {
                    if ($value === null && !$sparse) {
                        continue;
                    }
                    $this->writeBytesWithMajor(0x60, (string) $key);
                    if ($value === null) {
                        $this->buffer .= self::TOKEN_NULL;
                        continue;
                    }
                    $this->writeBytesWithMajor(
                        0x60,
                        $value instanceof EnumInterface
                            ? (string) $value->getValue()
                            : (string) $value
                    );
                }

                return;

            case ShapeType::Blob->value:
                foreach ($map as $key => $value) {
                    if ($value === null && !$sparse) {
                        continue;
                    }
                    $this->writeBytesWithMajor(0x60, (string) $key);
                    if ($value === null) {
                        $this->buffer .= self::TOKEN_NULL;
                        continue;
                    }
                    $this->writeBytesWithMajor(0x40, (string) $value);
                }

                return;

            case ShapeType::Timestamp->value:
                $timestampFormat = $info?->mapValueTimestampFormat;
                foreach ($map as $key => $value) {
                    if ($value === null && !$sparse) {
                        continue;
                    }
                    $this->writeBytesWithMajor(0x60, (string) $key);
                    if ($value === null) {
                        $this->buffer .= self::TOKEN_NULL;
                        continue;
                    }
                    $this->writeTimestampValue($value, $timestampFormat);
                }

                return;

            case ShapeType::Structure->value:
                foreach ($map as $key => $value) {
                    if ($value === null && !$sparse) {
                        continue;
                    }
                    $this->writeBytesWithMajor(0x60, (string) $key);
                    if ($value === null) {
                        $this->buffer .= self::TOKEN_NULL;
                        continue;
                    }
                    if (!$value instanceof SerializableStructInterface) {
                        throw new \InvalidArgumentException(
                            'Expected SerializableStructInterface for '
                            . 'structure value.'
                        );
                    }
                    $this->beginStructFrame($value->getSchema());
                    $value->serializeMembers($this);
                    $this->endStructFrame();
                }

                return;

            case ShapeType::List_->value:
                static $listExtKey = null;
                $listExtKey ??= CborSchemaExtensionProvider::key();
                /** @var CborSchemaExtension|null $nestedListInfo */
                $nestedListInfo = $valueSchema->getExtension($listExtKey);
                foreach ($map as $key => $value) {
                    if ($value === null && !$sparse) {
                        continue;
                    }
                    $this->writeBytesWithMajor(0x60, (string) $key);
                    if ($value === null) {
                        $this->buffer .= self::TOKEN_NULL;
                        continue;
                    }
                    $this->writeListValue(
                        $valueSchema,
                        $value,
                        $nestedListInfo
                    );
                }

                return;

            case ShapeType::Map->value:
                static $mapExtKey = null;
                $mapExtKey ??= CborSchemaExtensionProvider::key();
                /** @var CborSchemaExtension|null $nestedMapInfo */
                $nestedMapInfo = $valueSchema->getExtension($mapExtKey);
                foreach ($map as $key => $value) {
                    if ($value === null && !$sparse) {
                        continue;
                    }
                    $this->writeBytesWithMajor(0x60, (string) $key);
                    if ($value === null) {
                        $this->buffer .= self::TOKEN_NULL;
                        continue;
                    }
                    $this->writeMapValue(
                        $valueSchema,
                        $value,
                        $nestedMapInfo
                    );
                }

                return;
        }

        foreach ($map as $key => $value) {
            if ($value === null && !$sparse) {
                continue;
            }
            $this->writeBytesWithMajor(0x60, (string) $key);
            if ($value === null) {
                $this->buffer .= self::TOKEN_NULL;
                continue;
            }
            $this->writeValue($valueSchema, $value);
        }
    }

    /**
     * Write a timestamp payload with a pre-resolved explicit format.
     *
     * @return void
     */
    private function writeTimestampValue(
        mixed $value,
        ?TimestampFormat $explicitFormat
    ): void
    {
        if ($value instanceof \DateTimeInterface) {
            $value = (float) $value->format('U.u');
        }
        $value = (float) $value;

        $resolved = ($this->honorTimestampFormatTrait
            ? $explicitFormat
            : null
        ) ?? $this->settings->defaultTimestampFormat;
        if ($resolved !== TimestampFormat::EpochSeconds) {
            $this->writeBytesWithMajor(0x60, $resolved->format($value));

            return;
        }

        $this->buffer .= self::TOKEN_TAG_EPOCH;
        $integral = (int) $value;
        if ($value == $integral
            && $value <= PHP_INT_MAX
            && $value >= PHP_INT_MIN
        ) {
            $this->writeIntegerValue($integral);

            return;
        }

        $this->buffer .= self::TOKEN_FLOAT64
            . \pack('E', $value);
    }

    /**
     * Serialize a union payload as a single-entry CBOR map.
     *
     * @return void
     */
    private function writeUnionValue(
        Schema $schema,
        mixed $union,
        ?CborSchemaExtension $info = null
    ): void
    {
        $tag = null;
        $value = null;
        $unionSchema = null;
        $entry = null;

        if (!($union instanceof UnionInterface) && !\is_array($union)) {
            throw new \InvalidArgumentException(
                'Expected UnionInterface or array for union value.'
            );
        }

        if ($info === null) {
            static $extKey = null;
            $extKey ??= CborSchemaExtensionProvider::key();
            /** @var CborSchemaExtension|null $info */
            $info = $schema->getExtension($extKey);
        }

        if ($union instanceof UnionInterface) {
            $value = $union->value();
            $memberIndex = $union->getMemberIndex();
            if ($memberIndex >= 0) {
                $entry = $info?->unionEncodeByMemberIndex[$memberIndex]
                    ?? null;
            }
            if ($entry === null) {
                $tag = $union->tag();
                $unionSchema = $union->getSchema();
            }
        } else {
            foreach ($union as $candidateTag => $candidateValue) {
                if ($candidateValue === null) {
                    continue;
                }
                $tag = (string) $candidateTag;
                $value = $candidateValue;
                break;
            }
            if ($tag === null) {
                $this->writeMapStart(0);

                return;
            }
        }

        if ($entry === null) {
            $tag = (string) $tag;
            $resolvedSchema = $unionSchema
                ?? $info?->resolvedTargetSchema
                ?? $this->resolveTargetSchema($schema);
            $entry = $info?->unionEncodeByMemberName[$tag] ?? null;
            $variantSchema = $entry[2] ?? $resolvedSchema?->getMember($tag);
            if ($resolvedSchema !== null && $variantSchema === null) {
                throw new \InvalidArgumentException(
                    'Unknown union variant: ' . $tag
                );
            }
        }

        if ($entry !== null) {
            $this->buffer .= $entry[3];
            switch ($entry[1]) {
                case ShapeType::Boolean->value:
                    $this->buffer .= $value
                        ? self::TOKEN_TRUE
                        : self::TOKEN_FALSE;

                    return;

                case ShapeType::Byte->value:
                case ShapeType::Short->value:
                case ShapeType::Integer->value:
                case ShapeType::Long->value:
                    $this->writeIntegerValue((int) $value);

                    return;

                case ShapeType::IntEnum->value:
                    $this->writeIntegerValue(
                        $value instanceof EnumInterface
                            ? (int) $value->getValue()
                            : (int) $value
                    );

                    return;

                case ShapeType::Float_->value:
                    $this->buffer .= self::TOKEN_FLOAT32
                        . \pack('G', (float) $value);

                    return;

                case ShapeType::Double->value:
                    $this->buffer .= self::TOKEN_FLOAT64
                        . \pack('E', (float) $value);

                    return;

                case ShapeType::String_->value:
                case ShapeType::Enum->value:
                    $this->writeBytesWithMajor(
                        0x60,
                        $value instanceof EnumInterface
                            ? (string) $value->getValue()
                            : (string) $value
                    );

                    return;

                case ShapeType::Blob->value:
                    $this->writeBytesWithMajor(0x40, (string) $value);

                    return;

                case ShapeType::List_->value:
                    $this->writeListValue($entry[2], $value);

                    return;

                case ShapeType::Map->value:
                    $this->writeMapValue($entry[2], $value);

                    return;

                case ShapeType::Structure->value:
                    if (!$value instanceof SerializableStructInterface) {
                        throw new \InvalidArgumentException(
                            'Expected SerializableStructInterface for '
                            . 'structure value.'
                        );
                    }
                    $this->beginStructFrame($value->getSchema());
                    $value->serializeMembers($this);
                    $this->endStructFrame();

                    return;

                case ShapeType::Union->value:
                    $this->writeUnionValue($entry[2], $value);

                    return;
            }

            $this->writeValue($entry[2], $value);

            return;
        }

        $this->writeMapStart(1);
        if ($variantSchema !== null) {
            $this->buffer .= $this->resolveMemberNameBytes($variantSchema);
            $this->writeValue($variantSchema, $value);

            return;
        }

        $length = \strlen($tag);
        if ($length < 24) {
            $this->buffer .= \chr(0x60 | $length) . $tag;
        } else {
            switch (true) {
                case $length <= 0xFF:
                    $this->buffer .= "\x78" . \chr($length) . $tag;
                    break;

                case $length <= 0xFFFF:
                    $this->buffer .= "\x79" . \pack('n', $length) . $tag;
                    break;

                case $length <= 0xFFFF_FFFF:
                    $this->buffer .= "\x7A" . \pack('N', $length) . $tag;
                    break;

                default:
                    $high = ($length >> 32) & 0xFFFF_FFFF;
                    $low = $length & 0xFFFF_FFFF;
                    $this->buffer .= "\x7B" . \pack('NN', $high, $low) . $tag;
                    break;
            }
        }

        $value = (string) $value;
        $length = \strlen($value);
        if ($length < 24) {
            $this->buffer .= \chr(0x60 | $length) . $value;

            return;
        }

        switch (true) {
            case $length <= 0xFF:
                $this->buffer .= "\x78" . \chr($length) . $value;

                return;

            case $length <= 0xFFFF:
                $this->buffer .= "\x79" . \pack('n', $length) . $value;

                return;

            case $length <= 0xFFFF_FFFF:
                $this->buffer .= "\x7A" . \pack('N', $length) . $value;

                return;
        }

        $high = ($length >> 32) & 0xFFFF_FFFF;
        $low = $length & 0xFFFF_FFFF;
        $this->buffer .= "\x7B" . \pack('NN', $high, $low) . $value;
    }

    /**
     * Start buffering a structure frame.
     *
     * @return void
     */
    private function beginStructFrame(Schema $schema): void
    {
        $this->bufferStack[] = $this->buffer;
        $this->buffer = '';
        $this->fieldNameTableStack[] = $this->currentFieldNameTable;
        $this->currentFieldNameTable = $this->resolveFieldNameTable($schema);
        $this->structMemberCountStack[] = $this->currentStructMemberCount;
        $this->currentStructMemberCount = 0;
    }

    /**
     * Flush the buffered structure frame into the parent buffer.
     *
     * @return void
     */
    private function endStructFrame(): void
    {
        $encodedMembers = $this->buffer;
        $memberCount = $this->currentStructMemberCount;
        $this->currentStructMemberCount = \array_pop(
            $this->structMemberCountStack
        ) ?? -1;
        $this->currentFieldNameTable =
            \array_pop($this->fieldNameTableStack) ?: null;
        $this->buffer = \array_pop($this->bufferStack) ?? '';
        $this->writeMapStart($memberCount);
        $this->buffer .= $encodedMembers;
    }

    /**
     * Write a signed integer value.
     *
     * @return void
     */
    private function writeIntegerValue(int $value): void
    {
        if (isset(self::POS_INT_CACHE[$value])) {
            $this->buffer .= self::POS_INT_CACHE[$value];

            return;
        }
        if (isset(self::NEG_INT_CACHE[$value])) {
            $this->buffer .= self::NEG_INT_CACHE[$value];

            return;
        }

        if ($value >= 0) {
            $this->writeUnsignedWithMajor(0x00, $value);

            return;
        }

        $this->writeUnsignedWithMajor(0x20, -1 - $value);
    }

    /**
     * Write an array header.
     *
     * @return void
     */
    private function writeArrayStart(int $count): void
    {
        $this->writeUnsignedWithMajor(0x80, $count);
    }

    /**
     * Write a map header.
     *
     * @return void
     */
    private function writeMapStart(int $count): void
    {
        $this->writeUnsignedWithMajor(0xA0, $count);
    }

    /**
     * Write an unsigned integer with a major-type prefix.
     *
     * @return void
     */
    private function writeUnsignedWithMajor(int $majorBase, int $value): void
    {
        if ($value < 24) {
            $this->buffer .= \chr($majorBase | $value);

            return;
        }

        switch (true) {
            case $value <= 0xFF:
                $this->buffer .= \chr($majorBase | 24) . \chr($value);

                return;

            case $value <= 0xFFFF:
                $this->buffer .= \chr($majorBase | 25) . \pack('n', $value);

                return;

            case $value <= 0xFFFF_FFFF:
                $this->buffer .= \chr($majorBase | 26) . \pack('N', $value);

                return;
        }

        $high = ($value >> 32) & 0xFFFF_FFFF;
        $low = $value & 0xFFFF_FFFF;
        $this->buffer .= \chr($majorBase | 27) . \pack('NN', $high, $low);
    }

    /**
     * Count list entries that will be emitted on the wire.
     */
    private function countListItems(array $list, bool $sparse): int
    {
        if ($sparse) {
            return \count($list);
        }

        $count = 0;
        foreach ($list as $item) {
            if ($item !== null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Count map entries that will be emitted on the wire.
     */
    private function countMapEntries(array $map, bool $sparse): int
    {
        if ($sparse) {
            return \count($map);
        }

        $count = 0;
        foreach ($map as $value) {
            if ($value !== null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Write a byte or text string with its major-type prefix.
     *
     * @return void
     */
    private function writeBytesWithMajor(int $majorBase, string $value): void
    {
        $length = \strlen($value);
        if ($length < 24) {
            $this->buffer .= \chr($majorBase | $length) . $value;

            return;
        }

        switch (true) {
            case $length <= 0xFF:
                $this->buffer .= \chr($majorBase | 24) . \chr($length) . $value;

                return;

            case $length <= 0xFFFF:
                $this->buffer .= \chr($majorBase | 25)
                    . \pack('n', $length)
                    . $value;

                return;

            case $length <= 0xFFFF_FFFF:
                $this->buffer .= \chr($majorBase | 26)
                    . \pack('N', $length)
                    . $value;

                return;
        }

        $high = ($length >> 32) & 0xFFFF_FFFF;
        $low = $length & 0xFFFF_FFFF;
        $this->buffer .= \chr($majorBase | 27)
            . \pack('NN', $high, $low)
            . $value;
    }

    /**
     * Resolve the cached field-name lookup for a structure schema.
     *
     * @return array<int, string>|null
     */
    private function resolveFieldNameTable(Schema $schema): ?array
    {
        static $extKey = null;
        $extKey ??= CborSchemaExtensionProvider::key();
        /** @var CborSchemaExtension|null $info */
        $info = $schema->getExtension($extKey);
        if ($info !== null && $info->fieldNameTable !== []) {
            return $info->fieldNameTable;
        }

        $resolved = $info?->resolvedTargetSchema;
        if ($resolved !== null && $resolved !== $schema) {
            /** @var CborSchemaExtension|null $resolvedInfo */
            $resolvedInfo = $resolved->getExtension($extKey);
            return $resolvedInfo?->fieldNameTable;
        }

        return null;
    }

    /**
     * Resolve the target schema for a structure or union member.
     */
    private function resolveTargetSchema(Schema $schema): ?Schema
    {
        static $extKey = null;
        $extKey ??= CborSchemaExtensionProvider::key();
        /** @var CborSchemaExtension|null $info */
        $info = $schema->getExtension($extKey);
        return $info?->resolvedTargetSchema ?? $schema;
    }

    /**
     * Resolve pre-encoded member-name bytes for a member schema.
     */
    private function resolveMemberNameBytes(Schema $schema): string
    {
        static $extKey = null;
        $extKey ??= CborSchemaExtensionProvider::key();
        /** @var CborSchemaExtension|null $info */
        $info = $schema->getExtension($extKey);
        return $info?->memberNameBytes
            ?? CborSchemaExtensionProvider::encodeMemberName(
                (string) $schema->memberName
            );
    }

    /**
     * Check whether a decimal string fits in a signed 64-bit integer.
     */
    private function fitsSignedInt64(string $value): bool
    {
        $negative = $value[0] === '-';
        $digits = $negative ? \substr($value, 1) : $value;
        $digits = \ltrim($digits, '0');
        if ($digits === '') {
            return true;
        }

        $limit = $negative ? '9223372036854775808' : '9223372036854775807';
        $digitLength = \strlen($digits);
        $limitLength = \strlen($limit);
        if ($digitLength !== $limitLength) {
            return $digitLength < $limitLength;
        }

        return \strcmp($digits, $limit) <= 0;
    }

    /**
     * Subtract one from an unsigned decimal string.
     */
    private function decrementUnsignedDecimalString(string $digits): string
    {
        $chars = \str_split($digits);
        for ($i = \count($chars) - 1; $i >= 0; $i--) {
            if ($chars[$i] !== '0') {
                $chars[$i] = \chr(\ord($chars[$i]) - 1);
                break;
            }
            $chars[$i] = '9';
        }

        $result = \ltrim(\implode('', $chars), '0');
        return $result === '' ? '0' : $result;
    }

    /**
     * Convert an unsigned decimal string to big-endian bytes.
     */
    private function unsignedDecimalStringToBytes(string $digits): string
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
     * Subtract a small integer from a signed decimal string.
     */
    private function subtractSmallFromSignedDecimalString(string $value, int $delta): string
    {
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
            return '-'
                . $this->addUnsignedDecimalStrings($digits, $deltaString);
        }

        $comparison = $this->compareUnsignedDecimalStrings(
            $digits,
            $deltaString
        );
        if ($comparison === 0) {
            return '0';
        }
        if ($comparison > 0) {
            return $this->subtractUnsignedDecimalStrings($digits, $deltaString);
        }

        return '-'
            . $this->subtractUnsignedDecimalStrings($deltaString, $digits);
    }

    /**
     * Add two unsigned decimal strings.
     */
    private function addUnsignedDecimalStrings(string $left, string $right): string
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
    private function compareUnsignedDecimalStrings(string $left, string $right): int
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
    private function subtractUnsignedDecimalStrings(string $left, string $right): string
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
