<?php

declare(strict_types=1);

namespace Smithy\Codec\Cbor;

use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\SerializableShapeInterface;
use Smithy\Core\Serde\ShapeDeserializerInterface;
use Smithy\Core\Serde\ShapeType;
use Smithy\Core\Serde\TimestampFormat;
use Smithy\Core\Shape\BigDecimal;
use Smithy\Core\Shape\BigInteger;
use Smithy\Core\Shape\Document\StructDocument;
use Smithy\Core\Shape\Document\UnionDocument;

/**
 * Byte-level CBOR deserializer.
 *
 * Keeps the hot path as close to the wire as possible: raw payload string,
 * integer cursor, precomputed member-name bytes on `CborSchemaExtension`, and
 * literal-int schema dispatch. Struct member matching uses the same speculative
 * ordered-member fast path as the JSON deserializer, but compares raw UTF-8
 * bytes directly before falling back to a hashed scan. Several schema-specific
 * branches are intentionally duplicated to keep typed hydration and
 * sparse-container handling on branch-local hot paths.
 */
final class CborDeserializer implements ShapeDeserializerInterface
{
    private const MAJOR_POSINT = 0;
    private const MAJOR_NEGINT = 1;
    private const MAJOR_BYTESTRING = 2;
    private const MAJOR_TEXTSTRING = 3;
    private const MAJOR_ARRAY = 4;
    private const MAJOR_MAP = 5;
    private const MAJOR_TAG = 6;
    private const MAJOR_SIMPLE = 7;

    private const INFO_ONE_BYTE = 24;
    private const INFO_TWO_BYTES = 25;
    private const INFO_FOUR_BYTES = 26;
    private const INFO_EIGHT_BYTES = 27;
    private const INFO_INDEFINITE = 31;

    private const SIMPLE_FALSE = 20;
    private const SIMPLE_TRUE = 21;
    private const SIMPLE_NULL = 22;
    private const SIMPLE_UNDEFINED = 23;
    private const SIMPLE_HALF_FLOAT = 25;
    private const SIMPLE_FLOAT = 26;
    private const SIMPLE_DOUBLE = 27;

    private const TAG_EPOCH = 1;
    private const TAG_POS_BIGINT = 2;
    private const TAG_NEG_BIGINT = 3;
    private const TAG_DECIMAL = 4;

    private const FNV_OFFSET = 2166136261;
    private const FNV_PRIME = 16777619;
    private const MAX_DEPTH = 128;

    private readonly CborCodecSettings $settings;
    private readonly bool $honorTimestampFormatTrait;
    private readonly string $payload;
    private readonly int $length;
    private int $idx = 0;
    private int $depth = 0;

    /**
     * @param string $source Encoded CBOR payload.
     * @param CborCodecSettings|null $settings Codec behavior overrides.
     */
    public function __construct(
        string $source,
        ?CborCodecSettings $settings = null
    )
    {
        $this->payload = $source;
        $this->length = \strlen($source);
        $this->settings = $settings ?? new CborCodecSettings();
        $this->honorTimestampFormatTrait =
            $this->settings->honorTimestampFormatTrait;
    }

    /**
     * Deserialize a top-level structure or union into member slots.
     *
     * @param array<int, mixed> $members Member slots to populate in place.
     *
     * @return void
     */
    public function deserialize(Schema $schema, array &$members): void
    {
        if ($this->length === 0) {
            return;
        }

        if ($schema->type === ShapeType::Union->value) {
            static $unionExtKey = null;
            $unionExtKey ??= CborSchemaExtensionProvider::key();
            /** @var CborSchemaExtension|null $info */
            $info = $schema->getExtension($unionExtKey);
            $members[0] = $this->readUnion($schema, $info);
        } else {
            $this->readStruct($schema, $members);
        }

        if ($this->idx !== $this->length) {
            $this->malformed();
        }
    }

    /**
     * Read a typed structure into preallocated member slots.
     *
     * The schema-specific branches are intentionally specialized here to keep
     * member matching and typed hydration on the hot path.
     *
     * @param array<int, mixed> $members
     */
    private function readStruct(Schema $schema, array &$members): void
    {
        static $extKey = null;
        $extKey ??= CborSchemaExtensionProvider::key();
        /** @var CborSchemaExtension|null $info */
        $info = $schema->getExtension($extKey);
        if (!$info) {
            return;
        }

        // Guard nested payload depth.
        if (++$this->depth > self::MAX_DEPTH) {
            $this->malformed();
        }

        $initial = $this->readInitialByte();
        $infoByte = $initial & 0x1F;
        $indefinite = $infoByte === self::INFO_INDEFINITE;
        $remaining = $indefinite ? -1 : $this->readLengthFromInfo($infoByte);

        $orderedSchemas = $info->orderedMemberSchemas;
        $orderedIndexes = $info->orderedMemberIndexes;
        $orderedTypes = $info->orderedMemberTypes;
        $orderedNameBytes = $info->orderedMemberNameBytes;
        $orderedNameLengths = $info->orderedMemberNameLengths;
        $orderedNameHashes = $info->orderedMemberNameHashes;
        $orderedLookup1 = $info->orderedMemberLookup1;
        $orderedLookup2 = $info->orderedMemberLookup2;
        $orderedLookup3 = $info->orderedMemberLookup3;
        $orderedLookup4 = $info->orderedMemberLookup4;
        $orderedTargetClasses = $info->orderedMemberResolvedTargetClasses;
        $orderedTargetSchemas = $info->orderedMemberResolvedTargetSchemas;
        $orderedCount = \count($orderedSchemas);
        $payload = $this->payload;
        $payloadLength = $this->length;

        $hint = 0;

        while (true) {
            if ($indefinite) {
                if ($this->idx < $this->length
                    && \ord($this->payload[$this->idx]) === 0xFF
                ) {
                    $this->idx++;
                    break;
                }
            }
            if (!$indefinite && $remaining-- <= 0) {
                break;
            }

            $memberSlot = -1;
            $initial = $this->readInitialByte();

            $infoByte = $initial & 0x1F;
            if ($infoByte === self::INFO_INDEFINITE) {
                $wireKey = '';
                while (true) {
                    if ($this->idx < $payloadLength
                        && \ord($payload[$this->idx]) === 0xFF
                    ) {
                        $this->idx++;
                        break;
                    }
                    // Decode member names inline.
                    $chunkInfo = $this->readInitialByte() & 0x1F;
                    if ($chunkInfo >= self::INFO_ONE_BYTE) {
                        $wireKey .= $this->readStringPayload($chunkInfo);
                        continue;
                    }
                    if ($this->idx + $chunkInfo > $payloadLength) {
                        $this->malformed();
                    }
                    $wireKey .= \substr(
                        $payload,
                        $this->idx,
                        $chunkInfo
                    );
                    $this->idx += $chunkInfo;
                }
                if ($hint < $orderedCount
                    && $orderedNameBytes[$hint] === $wireKey
                ) {
                    $memberSlot = $hint;
                    $hint++;
                } else {
                    for ($i = 0; $i < $orderedCount; $i++) {
                        if ($orderedNameBytes[$i] === $wireKey) {
                            $memberSlot = $i;
                            break;
                        }
                    }
                }
            } else {
                $length = $this->readLengthFromInfo($infoByte);
                if ($this->idx + $length > $payloadLength) {
                    $this->malformed();
                }

                $start = $this->idx;
                $this->idx += $length;

                switch ($length) {
                    case 1:
                        $memberSlot =
                            $orderedLookup1[\ord($payload[$start])]
                            ?? -1;
                        break;

                    case 2:
                        $memberSlot = $orderedLookup2[
                            (\ord($payload[$start]) << 8)
                            | \ord($payload[$start + 1])
                        ] ?? -1;
                        break;

                    case 3:
                        $memberSlot = $orderedLookup3[
                            (\ord($payload[$start]) << 16)
                            | (\ord($payload[$start + 1]) << 8)
                            | \ord($payload[$start + 2])
                        ] ?? -1;
                        break;

                    case 4:
                        $memberSlot = $orderedLookup4[
                            (\ord($payload[$start]) << 24)
                            | (\ord($payload[$start + 1]) << 16)
                            | (\ord($payload[$start + 2]) << 8)
                            | \ord($payload[$start + 3])
                        ] ?? -1;
                        break;
                }

                // Prefer the next schema-ordered slot.
                if ($memberSlot === $hint) {
                    $hint++;
                }

                if ($memberSlot < 0
                    && $hint < $orderedCount
                    && $orderedNameLengths[$hint] === $length
                    && \substr_compare(
                        $payload,
                        $orderedNameBytes[$hint],
                        $start,
                        $length
                    ) === 0
                ) {
                    $memberSlot = $hint;
                    $hint++;
                }

                if ($memberSlot < 0) {
                    $hash = self::FNV_OFFSET;
                    $end = $start + $length;
                    for ($j = $start; $j < $end; $j++) {
                        $hash ^= \ord($payload[$j]);
                        $hash = ($hash * self::FNV_PRIME) & 0xFFFFFFFF;
                    }
                    $scanLimit = $hint + 4 < $orderedCount
                        ? $hint + 4
                        : $orderedCount;
                    for ($i = $hint; $i < $scanLimit; $i++) {
                        if ($orderedNameHashes[$i] === $hash
                            && $orderedNameLengths[$i] === $length
                            && \substr_compare(
                                $payload,
                                $orderedNameBytes[$i],
                                $start,
                                $length
                            ) === 0
                        ) {
                            $memberSlot = $i;
                            $hint = $i + 1;
                            break;
                        }
                    }

                    if ($memberSlot < 0) {
                        for ($i = 0; $i < $orderedCount; $i++) {
                            if ($orderedNameHashes[$i] === $hash
                                && $orderedNameLengths[$i] === $length
                                && \substr_compare(
                                    $payload,
                                    $orderedNameBytes[$i],
                                    $start,
                                    $length
                                ) === 0
                            ) {
                                $memberSlot = $i;
                                break;
                            }
                        }
                    }
                }
            }

            if ($memberSlot < 0) {
                $this->skipValue();
                continue;
            }

            if ($this->idx < $payloadLength
                && (($byte = \ord($payload[$this->idx])) === 0xF6
                    || $byte === 0xF7)
            ) {
                $this->idx++;
                continue;
            }

            $memberIndex = $orderedIndexes[$memberSlot];
            $memberType = $orderedTypes[$memberSlot];
            $memberSchema = $orderedSchemas[$memberSlot];
            $resolvedTargetClass = $orderedTargetClasses[$memberSlot];
            $resolvedTargetSchema = $orderedTargetSchemas[$memberSlot];

            $members[$memberIndex] = $this->readValue(
                $memberType,
                $memberSchema,
                $resolvedTargetClass,
                $resolvedTargetSchema
            );
        }

        $this->depth--;
    }

    /**
     * Read a typed union value.
     *
     * The variant branches remain inlined here to keep member resolution and
     * payload hydration branch-local.
     */
    private function readUnion(
        Schema $schema,
        ?CborSchemaExtension $info = null
    ): ?SerializableShapeInterface
    {
        if ($info === null) {
            static $extKey = null;
            $extKey ??= CborSchemaExtensionProvider::key();
            /** @var CborSchemaExtension|null $info */
            $info = $schema->getExtension($extKey);
        }
        if (!$info) {
            return null;
        }

        $unionSchema = $info->resolvedTargetSchema ?? $schema;
        $targetClass = $info->resolvedTargetClass ?? $unionSchema->targetClass;

        // Guard nested payload depth.
        if (++$this->depth > self::MAX_DEPTH) {
            $this->malformed();
        }

        if ($this->idx >= $this->length) {
            $this->malformed();
        }
        $payload = $this->payload;
        $payloadLength = $this->length;
        $initial = \ord($payload[$this->idx++]);
        $decodeEntries = $info->unionDecodeEntries;

        // Canonical definite single-entry unions are the common case.
        if ($initial === 0xA1) {
            $memberSlot = -1;
            $wireKey = '';
            if ($this->idx >= $payloadLength) {
                $this->malformed();
            }
            $initial = \ord($payload[$this->idx++]);
            $infoByte = $initial & 0x1F;

            if ($infoByte === self::INFO_INDEFINITE) {
                while (!$this->consumeBreak()) {
                    // Decode union tags inline.
                    $chunkInfo = $this->readInitialByte() & 0x1F;
                    if ($chunkInfo >= self::INFO_ONE_BYTE) {
                        $wireKey .= $this->readStringPayload($chunkInfo);
                        continue;
                    }
                    if ($this->idx + $chunkInfo > $payloadLength) {
                        $this->malformed();
                    }
                    $wireKey .= \substr(
                        $payload,
                        $this->idx,
                        $chunkInfo
                    );
                    $this->idx += $chunkInfo;
                }
                $orderedNameBytes = $info->orderedMemberNameBytes;
                $orderedCount = \count($decodeEntries);
                for ($i = 0; $i < $orderedCount; $i++) {
                    if ($orderedNameBytes[$i] === $wireKey) {
                        $memberSlot = $i;
                        break;
                    }
                }
            } else {
                $length = $infoByte < self::INFO_ONE_BYTE
                    ? $infoByte
                    : $this->readLengthFromInfo($infoByte);
                if ($this->idx + $length > $payloadLength) {
                    $this->malformed();
                }
                $start = $this->idx;
                $this->idx += $length;

                $memberSlot = match ($length) {
                    1 => $info->orderedMemberLookup1[
                        \ord($payload[$start])
                    ] ?? -1,
                    2 => $info->orderedMemberLookup2[
                        (\ord($payload[$start]) << 8)
                        | \ord($payload[$start + 1])
                    ] ?? -1,
                    3 => $info->orderedMemberLookup3[
                        (\ord($payload[$start]) << 16)
                        | (\ord($payload[$start + 1]) << 8)
                        | \ord($payload[$start + 2])
                    ] ?? -1,
                    4 => $info->orderedMemberLookup4[
                        (\ord($payload[$start]) << 24)
                        | (\ord($payload[$start + 1]) << 16)
                        | (\ord($payload[$start + 2]) << 8)
                        | \ord($payload[$start + 3])
                    ] ?? -1,
                    default => -1,
                };

                if ($length === 6
                    && \substr_compare($payload, '__type', $start, 6) === 0
                ) {
                    $wireKey = '__type';
                } elseif ($memberSlot < 0) {
                    $orderedNameBytes = $info->orderedMemberNameBytes;
                    $orderedNameLengths = $info->orderedMemberNameLengths;
                    $orderedNameHashes = $info->orderedMemberNameHashes;
                    $orderedCount = \count($decodeEntries);
                    $hash = self::FNV_OFFSET;
                    $end = $start + $length;
                    for ($j = $start; $j < $end; $j++) {
                        $hash ^= \ord($payload[$j]);
                        $hash = ($hash * self::FNV_PRIME) & 0xFFFFFFFF;
                    }
                    for ($i = 0; $i < $orderedCount; $i++) {
                        if ($orderedNameHashes[$i] === $hash
                            && $orderedNameLengths[$i] === $length
                            && \substr_compare(
                                $payload,
                                $orderedNameBytes[$i],
                                $start,
                                $length
                            ) === 0
                        ) {
                            $memberSlot = $i;
                            break;
                        }
                    }
                    if ($memberSlot < 0) {
                        $wireKey = \substr($payload, $start, $length);
                    }
                }
            }

            if ($wireKey === '__type') {
                $this->skipValue();
                $this->depth--;
                return null;
            }

            if ($this->idx < $payloadLength
                && (($byte = \ord($payload[$this->idx])) === 0xF6
                    || $byte === 0xF7)
            ) {
                $this->idx++;
                $this->depth--;
                return null;
            }

            if ($memberSlot < 0) {
                $value = $this->readUntypedValue();
                $result = $targetClass !== null
                    ? $targetClass::unknown($wireKey, $value)
                    : UnionDocument::unknown(
                        $unionSchema,
                        $wireKey,
                        $value
                    );
            } else {
                $entry = $decodeEntries[$memberSlot];
                switch ($entry[2]) {
                    case ShapeType::Blob->value:
                    case ShapeType::String_->value:
                    case ShapeType::Enum->value:
                        if ($this->idx >= $payloadLength) {
                            $this->malformed();
                        }
                        $stringInfo = \ord($payload[$this->idx++]) & 0x1F;
                        if ($stringInfo >= self::INFO_ONE_BYTE) {
                            $value = $this->readStringPayload($stringInfo);
                        } else {
                            if ($this->idx + $stringInfo > $payloadLength) {
                                $this->malformed();
                            }
                            $start = $this->idx;
                            $this->idx += $stringInfo;
                            $value = \substr(
                                $payload,
                                $start,
                                $stringInfo
                            );
                        }
                        if (
                            $entry[2] === ShapeType::Enum->value
                            && $entry[4] !== null
                        ) {
                            $value = $entry[4]::fromValue($value);
                        }
                        break;

                    case ShapeType::Boolean->value:
                        if ($this->idx >= $payloadLength) {
                            $this->malformed();
                        }
                        $value = \ord($payload[$this->idx++]) === 0xF5;
                        break;

                    case ShapeType::Byte->value:
                    case ShapeType::Short->value:
                    case ShapeType::Integer->value:
                    case ShapeType::Long->value:
                    case ShapeType::IntEnum->value:
                        if ($this->idx >= $payloadLength) {
                            $this->malformed();
                        }
                        $numericInitial = \ord($payload[$this->idx++]);
                        $value = $this->readIntegerFromMajorAndInfo(
                            $numericInitial >> 5,
                            $numericInitial & 0x1F
                        );
                        if (
                            $entry[2] === ShapeType::IntEnum->value
                            && $entry[4] !== null
                        ) {
                            $value = $entry[4]::fromValue($value);
                        }
                        break;

                    case ShapeType::Float_->value:
                    case ShapeType::Double->value:
                        if ($this->idx >= $payloadLength) {
                            $this->malformed();
                        }
                        $value = $this->readNumericFromInitial(
                            \ord($payload[$this->idx++])
                        );
                        break;

                    default:
                        $value = $this->readValue(
                            $entry[2],
                            $entry[3],
                            $entry[4],
                            $entry[5]
                        );
                        break;
                }
                if ($targetClass !== null) {
                    $result = $info->hasIndexedUnionFactory
                        ? $targetClass::createByIndex($entry[0], $value)
                        : $targetClass::create($entry[1], $value);
                } else {
                    $result = UnionDocument::create(
                        $unionSchema,
                        $entry[1],
                        $value
                    );
                }
            }

            $this->depth--;

            return $result;
        }

        $infoByte = $initial & 0x1F;
        $indefinite = $infoByte === self::INFO_INDEFINITE;
        $remaining = $indefinite ? -1 : $this->readLengthFromInfo($infoByte);
        $orderedNameBytes = $info->orderedMemberNameBytes;
        $orderedNameLengths = $info->orderedMemberNameLengths;
        $orderedNameHashes = $info->orderedMemberNameHashes;
        $orderedLookup1 = $info->orderedMemberLookup1;
        $orderedLookup2 = $info->orderedMemberLookup2;
        $orderedLookup3 = $info->orderedMemberLookup3;
        $orderedLookup4 = $info->orderedMemberLookup4;
        $orderedCount = \count($decodeEntries);
        $hint = 0;
        $result = null;

        while (true) {
            if ($indefinite) {
                if ($this->idx < $payloadLength
                    && \ord($payload[$this->idx]) === 0xFF
                ) {
                    $this->idx++;
                    break;
                }
            }
            if (!$indefinite && $remaining-- <= 0) {
                break;
            }

            $memberSlot = -1;
            $wireKey = '';
            $initial = $this->readInitialByte();

            $infoByte = $initial & 0x1F;
            if ($infoByte === self::INFO_INDEFINITE) {
                $wireKey = '';
                while (true) {
                    if ($this->idx < $payloadLength
                        && \ord($payload[$this->idx]) === 0xFF
                    ) {
                        $this->idx++;
                        break;
                    }
                    // Decode union tags inline.
                    $chunkInfo = $this->readInitialByte() & 0x1F;
                    if ($chunkInfo >= self::INFO_ONE_BYTE) {
                        $wireKey .= $this->readStringPayload($chunkInfo);
                        continue;
                    }
                    if ($this->idx + $chunkInfo > $payloadLength) {
                        $this->malformed();
                    }
                    $wireKey .= \substr(
                        $payload,
                        $this->idx,
                        $chunkInfo
                    );
                    $this->idx += $chunkInfo;
                }
                if ($hint < $orderedCount
                    && $orderedNameBytes[$hint] === $wireKey
                ) {
                    $memberSlot = $hint;
                    $hint++;
                } else {
                    for ($i = 0; $i < $orderedCount; $i++) {
                        if ($orderedNameBytes[$i] === $wireKey) {
                            $memberSlot = $i;
                            break;
                        }
                    }
                }
            } else {
                $length = $this->readLengthFromInfo($infoByte);
                if ($this->idx + $length > $payloadLength) {
                    $this->malformed();
                }

                $start = $this->idx;
                $this->idx += $length;

                switch ($length) {
                    case 1:
                        $memberSlot =
                            $orderedLookup1[\ord($payload[$start])]
                            ?? -1;
                        break;

                    case 2:
                        $memberSlot = $orderedLookup2[
                            (\ord($payload[$start]) << 8)
                            | \ord($payload[$start + 1])
                        ] ?? -1;
                        break;

                    case 3:
                        $memberSlot = $orderedLookup3[
                            (\ord($payload[$start]) << 16)
                            | (\ord($payload[$start + 1]) << 8)
                            | \ord($payload[$start + 2])
                        ] ?? -1;
                        break;

                    case 4:
                        $memberSlot = $orderedLookup4[
                            (\ord($payload[$start]) << 24)
                            | (\ord($payload[$start + 1]) << 16)
                            | (\ord($payload[$start + 2]) << 8)
                            | \ord($payload[$start + 3])
                        ] ?? -1;
                        break;
                }

                if ($length === 6
                    && \substr_compare($payload, '__type', $start, 6) === 0
                ) {
                    $wireKey = '__type';
                }

                if ($wireKey === '' && $memberSlot === $hint) {
                    $hint++;
                }

                if ($wireKey === ''
                    && $memberSlot < 0
                    && $hint < $orderedCount
                    && $orderedNameLengths[$hint] === $length
                    && \substr_compare(
                        $payload,
                        $orderedNameBytes[$hint],
                        $start,
                        $length
                    ) === 0
                ) {
                    $memberSlot = $hint;
                    $hint++;
                    $wireKey = $orderedNameBytes[$memberSlot];
                }

                if ($wireKey === '' && $memberSlot < 0) {
                    $hash = self::FNV_OFFSET;
                    $end = $start + $length;
                    for ($j = $start; $j < $end; $j++) {
                        $hash ^= \ord($payload[$j]);
                        $hash = ($hash * self::FNV_PRIME) & 0xFFFFFFFF;
                    }
                    $scanLimit = $hint + 4 < $orderedCount
                        ? $hint + 4
                        : $orderedCount;
                    for ($i = $hint; $i < $scanLimit; $i++) {
                        if ($orderedNameHashes[$i] === $hash
                            && $orderedNameLengths[$i] === $length
                            && \substr_compare(
                                $payload,
                                $orderedNameBytes[$i],
                                $start,
                                $length
                            ) === 0
                        ) {
                            $memberSlot = $i;
                            $hint = $i + 1;
                            $wireKey = $orderedNameBytes[$memberSlot];
                            break;
                        }
                    }

                    if ($memberSlot < 0 && $wireKey === '') {
                        for ($i = 0; $i < $orderedCount; $i++) {
                            if ($orderedNameHashes[$i] === $hash
                                && $orderedNameLengths[$i] === $length
                                && \substr_compare(
                                    $payload,
                                    $orderedNameBytes[$i],
                                    $start,
                                    $length
                                ) === 0
                            ) {
                                $memberSlot = $i;
                                $wireKey = $orderedNameBytes[$memberSlot];
                                break;
                            }
                        }
                    }

                    if ($memberSlot < 0 && $wireKey === '') {
                        $wireKey = \substr($payload, $start, $length);
                    }
                }
            }

            if ($wireKey === '__type') {
                $this->skipValue();
                continue;
            }

            if ($this->idx < $payloadLength
                && (($byte = \ord($payload[$this->idx])) === 0xF6
                    || $byte === 0xF7)
            ) {
                $this->idx++;
                continue;
            }

            // Drop entries after the first resolved variant.
            if ($result !== null) {
                $this->skipValue();
                continue;
            }

            if ($memberSlot < 0) {
                $value = $this->readUntypedValue();
                $result = $targetClass !== null
                    ? $targetClass::unknown($wireKey, $value)
                    : UnionDocument::unknown(
                        $unionSchema,
                        $wireKey,
                        $value
                    );
                if (!$indefinite && $remaining === 0) {
                    break;
                }
                continue;
            }

            $entry = $decodeEntries[$memberSlot];
            $value = $this->readValue(
                $entry[2],
                $entry[3],
                $entry[4],
                $entry[5]
            );
            if ($targetClass !== null) {
                $result = $info->hasIndexedUnionFactory
                    ? $targetClass::createByIndex($entry[0], $value)
                    : $targetClass::create($entry[1], $value);
            } else {
                $result = UnionDocument::create(
                    $unionSchema,
                    $entry[1],
                    $value
                );
            }
            if (!$indefinite && $remaining === 0) {
                break;
            }
        }

        $this->depth--;

        return $result;
    }

    /**
     * Read a typed member value.
     */
    private function readValue(
        int $type,
        Schema $schema,
        ?string $resolvedTargetClass = null,
        ?Schema $resolvedTargetSchema = null
    ): mixed {
        switch ($type) {
            case ShapeType::Blob->value:
            case ShapeType::String_->value:
                $info = $this->readInitialByte() & 0x1F;
                if ($info >= self::INFO_ONE_BYTE) {
                    return $this->readStringPayload($info);
                }
                if ($this->idx + $info > $this->length) {
                    $this->malformed();
                }
                $start = $this->idx;
                $this->idx += $info;

                return \substr($this->payload, $start, $info);

            case ShapeType::Enum->value:
                $info = $this->readInitialByte() & 0x1F;
                if ($info >= self::INFO_ONE_BYTE) {
                    $value = $this->readStringPayload($info);
                } else {
                    if ($this->idx + $info > $this->length) {
                        $this->malformed();
                    }
                    $start = $this->idx;
                    $this->idx += $info;
                    $value = \substr($this->payload, $start, $info);
                }

                return $resolvedTargetClass !== null
                    ? $resolvedTargetClass::fromValue($value)
                    : $value;

            case ShapeType::Boolean->value:
                return $this->readInitialByte() === 0xF5;

            case ShapeType::Byte->value:
            case ShapeType::Short->value:
            case ShapeType::Integer->value:
            case ShapeType::Long->value:
                return $this->readSignedInteger();

            case ShapeType::IntEnum->value:
                $value = $this->readSignedInteger();
                return $resolvedTargetClass !== null
                    ? $resolvedTargetClass::fromValue($value)
                    : $value;

            case ShapeType::Float_->value:
            case ShapeType::Double->value:
                return $this->readNumeric();

            case ShapeType::BigInteger->value:
                return $this->readBigInteger();

            case ShapeType::BigDecimal->value:
                return $this->readBigDecimal();

            case ShapeType::Timestamp->value:
                $initial = $this->readInitialByte();
                $major = $initial >> 5;
                if ($major === self::MAJOR_TAG) {
                    $tag = $this->readLengthFromInfo($initial & 0x1F);
                    if ($tag !== self::TAG_EPOCH) {
                        $this->malformed();
                    }

                    return (float) $this->readNumericFromInitial(
                        $this->readInitialByte()
                    );
                }

                if ($major === self::MAJOR_TEXTSTRING) {
                    $format = ($this->honorTimestampFormatTrait
                        ? TimestampFormat::fromTraitString(
                            $schema->getTrait(Schema::T_TIMESTAMP_FORMAT)
                        )
                        : null
                    ) ?? $this->settings->defaultTimestampFormat;

                    return $format->parse($this->readStringFromInitial($initial));
                }

                return match ($major) {
                    self::MAJOR_POSINT,
                    self::MAJOR_NEGINT,
                    self::MAJOR_SIMPLE =>
                        (float) $this->readNumericFromInitial($initial),
                    default => $this->malformed(),
                };

            case ShapeType::Structure->value:
                static $structExtKey = null;
                $structExtKey ??= CborSchemaExtensionProvider::key();
                $targetSchema = $resolvedTargetSchema ?? $schema;
                /** @var CborSchemaExtension|null $targetInfo */
                $targetInfo = $targetSchema->getExtension($structExtKey);
                $nestedMembers = $targetInfo?->membersTemplate ?? [];
                $this->readStruct($targetSchema, $nestedMembers);
                if ($resolvedTargetClass !== null) {
                    return $resolvedTargetClass::fromMembers($nestedMembers);
                }

                return StructDocument::fromMembers(
                    $targetSchema,
                    $nestedMembers
                );

            case ShapeType::Union->value:
                $targetSchema = $resolvedTargetSchema ?? $schema;
                static $unionExtKey = null;
                $unionExtKey ??= CborSchemaExtensionProvider::key();
                /** @var CborSchemaExtension|null $unionInfo */
                $unionInfo = $targetSchema->getExtension($unionExtKey);

                return $this->readUnion($targetSchema, $unionInfo);

            case ShapeType::List_->value:
                return $this->readList($schema);

            case ShapeType::Map->value:
                return $this->readMap($schema);

            case ShapeType::Document->value:
                throw new \LogicException(
                    'rpcv2-cbor does not support'
                    . ' Smithy document shapes.'
                );

            default:
                return $this->readUntypedValue();
        }
    }

    /**
     * Read a typed list value.
     *
     * Sparse handling and container framing remain on the hot path while item
     * dispatch is delegated to readValue().
     */
    private function readList(Schema $schema): array
    {
        static $extKey = null;
        $extKey ??= CborSchemaExtensionProvider::key();
        /** @var CborSchemaExtension|null $info */
        $info = $schema->getExtension($extKey);
        $itemSchema = $info?->listMemberSchema ?? $schema->listMember;
        if (!$itemSchema) {
            $this->skipValue();
            return [];
        }

        // Guard nested payload depth.
        if (++$this->depth > self::MAX_DEPTH) {
            $this->malformed();
        }

        $initial = $this->readInitialByte();
        $infoByte = $initial & 0x1F;
        $indefinite = $infoByte === self::INFO_INDEFINITE;
        $remaining = $indefinite ? -1 : $this->readLengthFromInfo($infoByte);

        $out = [];
        if ($remaining === 0) {
            $this->depth--;
            return $out;
        }

        $itemType = $info?->listMemberType ?? 0;
        $resolvedTargetClass = $info?->listMemberResolvedTargetClass;
        $resolvedTargetSchema = $info?->listMemberResolvedTargetSchema;
        $unionInfo = $info?->listMemberUnionInfo;
        $payload = $this->payload;
        $payloadLength = $this->length;
        $sparse = $info?->sparse ?? false;

        while (true) {
            if ($indefinite) {
                if ($this->idx < $payloadLength
                    && \ord($payload[$this->idx]) === 0xFF
                ) {
                    $this->idx++;
                    break;
                }
            }
            if (!$indefinite && $remaining-- <= 0) {
                break;
            }

            if ($this->idx < $payloadLength
                && (($byte = \ord($payload[$this->idx])) === 0xF6
                    || $byte === 0xF7)
            ) {
                $this->idx++;
                if ($sparse) {
                    $out[] = null;
                }
                continue;
            }

            $out[] = $itemType === ShapeType::Union->value
                && $unionInfo !== null
                ? $this->readUnion(
                    $resolvedTargetSchema ?? $itemSchema,
                    $unionInfo
                )
                : $this->readValue(
                    $itemType,
                    $itemSchema,
                    $resolvedTargetClass,
                    $resolvedTargetSchema
                );
        }

        $this->depth--;

        return $out;
    }

    /**
     * Read a typed map value.
     *
     * Key parsing, sparse handling, and container framing remain on the hot
     * path while value dispatch is delegated to readValue().
     */
    private function readMap(Schema $schema): array
    {
        static $extKey = null;
        $extKey ??= CborSchemaExtensionProvider::key();
        /** @var CborSchemaExtension|null $info */
        $info = $schema->getExtension($extKey);
        $valueSchema = $info?->mapValueSchema ?? $schema->mapValue;
        if (!$valueSchema) {
            $this->skipValue();
            return [];
        }

        // Guard nested payload depth.
        if (++$this->depth > self::MAX_DEPTH) {
            $this->malformed();
        }

        $initial = $this->readInitialByte();
        $infoByte = $initial & 0x1F;
        $indefinite = $infoByte === self::INFO_INDEFINITE;
        $remaining = $indefinite ? -1 : $this->readLengthFromInfo($infoByte);

        $out = [];
        if ($remaining === 0) {
            $this->depth--;
            return $out;
        }

        $valueType = $info?->mapValueType ?? 0;
        $resolvedTargetClass = $info?->mapValueResolvedTargetClass;
        $resolvedTargetSchema = $info?->mapValueResolvedTargetSchema;
        $unionInfo = $info?->mapValueUnionInfo;
        $payload = $this->payload;
        $payloadLength = $this->length;
        $sparse = $info?->sparse ?? false;

        while (true) {
            if ($indefinite) {
                if ($this->idx < $payloadLength
                    && \ord($payload[$this->idx]) === 0xFF
                ) {
                    $this->idx++;
                    break;
                }
            }

            if (!$indefinite && $remaining-- <= 0) {
                break;
            }

            $keyInfo = $this->readInitialByte() & 0x1F;
            // Decode map keys inline.
            if ($keyInfo >= self::INFO_ONE_BYTE) {
                $key = $this->readStringPayload($keyInfo);
            } else {
                if ($this->idx + $keyInfo > $payloadLength) {
                    $this->malformed();
                }
                $key = \substr($payload, $this->idx, $keyInfo);
                $this->idx += $keyInfo;
            }

            if ($this->idx < $payloadLength
                && (($byte = \ord($payload[$this->idx])) === 0xF6
                    || $byte === 0xF7)
            ) {
                $this->idx++;
                if ($sparse) {
                    $out[$key] = null;
                }
                continue;
            }

            $out[$key] = $valueType === ShapeType::Union->value
                && $unionInfo !== null
                ? $this->readUnion(
                    $resolvedTargetSchema ?? $valueSchema,
                    $unionInfo
                )
                : $this->readValue(
                    $valueType,
                    $valueSchema,
                    $resolvedTargetClass,
                    $resolvedTargetSchema
                );
        }

        $this->depth--;

        return $out;
    }

    /**
     * Read a signed CBOR integer.
     */
    private function readSignedInteger(): int
    {
        $initial = $this->readInitialByte();

        return $this->readIntegerFromMajorAndInfo(
            $initial >> 5,
            $initial & 0x1F
        );
    }

    /**
     * Read a numeric CBOR value.
     */
    private function readNumeric(): float|int
    {
        return $this->readNumericFromInitial($this->readInitialByte());
    }

    /**
     * Read a Smithy BigInteger value.
     */
    private function readBigInteger(): BigInteger
    {
        $initial = $this->readInitialByte();
        $major = $initial >> 5;
        $info = $initial & 0x1F;

        if ($major === self::MAJOR_POSINT) {
            return new BigInteger((string) $this->readLengthFromInfo($info));
        }
        if ($major === self::MAJOR_NEGINT) {
            return new BigInteger(
                (string) $this->decodeNegativeInteger(
                    $this->readLengthFromInfo($info)
                )
            );
        }
        return $this->readBigIntegerFromTagInfo(
            $this->readLengthFromInfo($info)
        );
    }

    /**
     * Read a Smithy BigDecimal value.
     */
    private function readBigDecimal(): BigDecimal
    {
        $initial = $this->readInitialByte();
        $info = $initial & 0x1F;
        $this->readLengthFromInfo($info);

        return $this->readBigDecimalPayload();
    }

    /**
     * Read an integer from a parsed major type and additional info.
     */
    private function readIntegerFromMajorAndInfo(
        int $major,
        int $info
    ): int {
        $unsigned = $this->readLengthFromInfo($info);

        return $major === self::MAJOR_POSINT
            ? $unsigned
            : $this->decodeNegativeInteger($unsigned);
    }

    /**
     * Decode a CBOR negative integer from its unsigned payload.
     */
    private function decodeNegativeInteger(int $unsigned): int
    {
        return $unsigned === PHP_INT_MAX ? PHP_INT_MIN : -1 - $unsigned;
    }

    /**
     * Read a numeric value from a parsed initial byte.
     */
    private function readNumericFromInitial(int $initial): float|int
    {
        $major = $initial >> 5;
        $info = $initial & 0x1F;

        return $major === self::MAJOR_SIMPLE
            ? $this->readFloatFromInfo($info)
            : $this->readIntegerFromMajorAndInfo(
                $major,
                $info
            );
    }

    /**
     * Read a floating-point simple value.
     */
    private function readFloatFromInfo(int $info): float
    {
        return match ($info) {
            self::SIMPLE_HALF_FLOAT => $this->readHalfFloat(),
            self::SIMPLE_FLOAT => $this->readFloat32(),
            self::SIMPLE_DOUBLE => $this->readFloat64(),
            default => $this->malformed(),
        };
    }

    /**
     * Read a 32-bit float from the payload.
     */
    private function readFloat32(): float
    {
        $this->ensureRemainingBytes(4);
        $idx = $this->idx;
        $this->idx = $idx + 4;

        return \unpack('G', $this->payload, $idx)[1];
    }

    /**
     * Read a 64-bit float from the payload.
     */
    private function readFloat64(): float
    {
        $this->ensureRemainingBytes(8);
        $idx = $this->idx;
        $this->idx = $idx + 8;

        return \unpack('E', $this->payload, $idx)[1];
    }

    /**
     * Read a string-like value from a parsed initial byte.
     */
    private function readStringFromInitial(int $initial): string
    {
        $info = $initial & 0x1F;

        return $info < self::INFO_ONE_BYTE
            ? $this->readBytes($info)
            : $this->readStringPayload($info);
    }

    /**
     * Read a string-like payload after its initial byte has been parsed.
     */
    private function readStringPayload(int $info): string
    {
        if ($info === self::INFO_INDEFINITE) {
            $chunks = '';
            while (!$this->consumeBreak()) {
                $chunks .= $this->readStringFromInitial(
                    $this->readInitialByte()
                );
            }

            return $chunks;
        }

        return $this->readBytes($this->readLengthFromInfo($info));
    }

    /**
     * Read a fixed number of bytes from the payload.
     */
    private function readBytes(int $length): string
    {
        if ($this->idx + $length > $this->length) {
            $this->malformed();
        }

        $start = $this->idx;
        $this->idx += $length;

        return \substr($this->payload, $start, $length);
    }

    /**
     * Read an untyped tagged value.
     */
    private function readTaggedUntypedValue(int $info): mixed
    {
        $tag = $this->readLengthFromInfo($info);

        return match ($tag) {
            self::TAG_EPOCH => (float) $this->readNumericFromInitial(
                $this->readInitialByte()
            ),
            self::TAG_POS_BIGINT,
            self::TAG_NEG_BIGINT => $this->readBigIntegerFromTagInfo($tag),
            self::TAG_DECIMAL => $this->readBigDecimalPayload(),
            default => $this->readUntypedValue(),
        };
    }

    /**
     * Read a BigInteger payload after its tag has been parsed.
     */
    private function readBigIntegerFromTagInfo(int $info): BigInteger
    {
        if ($info !== self::TAG_POS_BIGINT && $info !== self::TAG_NEG_BIGINT) {
            $this->malformed();
        }

        $bytesInfo = $this->readInitialByte() & 0x1F;
        // BigInteger tags carry raw byte strings.
        $value = CborBigNumber::fromUnsignedBytes(
            $bytesInfo < self::INFO_ONE_BYTE
                ? $this->readBytes($bytesInfo)
                : $this->readStringPayload($bytesInfo)
        );

        if ($info === self::TAG_NEG_BIGINT) {
            $value = '-' . CborBigNumber::increment($value);
        }

        return new BigInteger($value);
    }

    /**
     * Read a decimal-fraction payload after its tag has been parsed.
     */
    private function readBigDecimalPayload(): BigDecimal
    {
        $initial = $this->readInitialByte();
        $info = $initial & 0x1F;

        if ($this->readLengthFromInfo($info) !== 2) {
            $this->malformed();
        }

        $exponent = $this->readBigInteger()->value;
        $mantissa = $this->readBigInteger()->value;

        return new BigDecimal(
            $exponent === '0'
                ? $mantissa
                : $mantissa . 'e' . $exponent
        );
    }

    /**
     * Read a CBOR half-precision float.
     */
    private function readHalfFloat(): float
    {
        $this->ensureRemainingBytes(2);
        $bits = (\ord($this->payload[$this->idx]) << 8)
            | \ord($this->payload[$this->idx + 1]);
        $this->idx += 2;
        $sign = ($bits >> 15) & 0x01;
        $exp = ($bits >> 10) & 0x1F;
        $mant = $bits & 0x3FF;

        return match ($exp) {
            0 => $mant === 0
                ? ($sign ? -0.0 : 0.0)
                : ($sign ? -1 : 1) * (2 ** -14) * ($mant / 1024),
            31 => $mant === 0 ? ($sign ? -INF : INF) : NAN,
            default => (float) (
                ($sign ? -1 : 1)
                * (2 ** ($exp - 15))
                * (1 + $mant / 1024)
            ),
        };
    }

    /**
     * Read an untyped CBOR value.
     */
    private function readUntypedValue(): mixed
    {
        $initial = $this->readInitialByte();
        $major = $initial >> 5;
        $info = $initial & 0x1F;

        switch ($major) {
            case self::MAJOR_POSINT:
            case self::MAJOR_NEGINT:
                return $this->readIntegerFromMajorAndInfo(
                    $major,
                    $info
                );

            case self::MAJOR_BYTESTRING:
            case self::MAJOR_TEXTSTRING:
                return $info < self::INFO_ONE_BYTE
                    ? $this->readBytes($info)
                    : $this->readStringPayload($info);

            case self::MAJOR_ARRAY:
                return $this->readUntypedArray($info);

            case self::MAJOR_MAP:
                return $this->readUntypedMap($info);

            case self::MAJOR_TAG:
                return $this->readTaggedUntypedValue($info);

            case self::MAJOR_SIMPLE:
                return match ($info) {
                    self::SIMPLE_FALSE => false,
                    self::SIMPLE_TRUE => true,
                    self::SIMPLE_NULL,
                    self::SIMPLE_UNDEFINED => null,
                    self::SIMPLE_HALF_FLOAT,
                    self::SIMPLE_FLOAT,
                    self::SIMPLE_DOUBLE => $this->readFloatFromInfo($info),
                    default => $this->malformed(),
                };
        }

        $this->malformed();
    }

    /**
     * Skip a CBOR value without decoding it.
     *
     * @return void
     */
    private function skipValue(): void
    {
        $initial = $this->readInitialByte();
        $major = $initial >> 5;
        $info = $initial & 0x1F;

        switch ($major) {
            case 0:
            case 1:
                $this->skipLengthValue($info);
                return;

            case 2:
            case 3:
                if ($info === self::INFO_INDEFINITE) {
                    while (!$this->consumeBreak()) {
                        $this->skipValue();
                    }
                } else {
                    $bytes = $this->readLengthFromInfo($info);
                    $this->ensureRemainingBytes($bytes);
                    $this->idx += $bytes;
                }
                return;

            case 4:
                $this->skipCollection($info, false);
                return;

            case 5:
                $this->skipCollection($info, true);
                return;

            case 6:
                $this->skipLengthValue($info);
                $this->skipValue();
                return;

            case 7:
                $bytes = match ($info) {
                    self::INFO_ONE_BYTE => 1,
                    self::SIMPLE_HALF_FLOAT => 2,
                    self::SIMPLE_FLOAT => 4,
                    self::SIMPLE_DOUBLE => 8,
                    default => 0,
                };
                if ($bytes !== 0) {
                    $this->ensureRemainingBytes($bytes);
                    $this->idx += $bytes;
                }
                return;
        }

        $this->malformed();
    }

    /**
     * Skip an array or map payload.
     */
    private function skipCollection(int $info, bool $map): void
    {
        if (++$this->depth > self::MAX_DEPTH) {
            $this->malformed();
        }

        $indefinite = $info === self::INFO_INDEFINITE;
        $remaining = $indefinite
            ? -1
            : $this->readLengthFromInfo($info);

        while (true) {
            if ($indefinite && $this->consumeBreak()) {
                break;
            }
            if (!$indefinite && $remaining-- <= 0) {
                break;
            }
            $this->skipValue();
            if ($map) {
                $this->skipValue();
            }
        }

        $this->depth--;
    }

    /**
     * Read an initial CBOR byte.
     */
    private function readInitialByte(): int
    {
        if ($this->idx >= $this->length) {
            $this->malformed();
        }

        return \ord($this->payload[$this->idx++]);
    }

    /**
     * Read an untyped CBOR array.
     *
     * @return list<mixed>
     */
    private function readUntypedArray(int $info): array
    {
        if (++$this->depth > self::MAX_DEPTH) {
            $this->malformed();
        }

        $indefinite = $info === self::INFO_INDEFINITE;
        $remaining = $indefinite ? -1 : $this->readLengthFromInfo($info);
        $out = [];

        while (true) {
            if ($indefinite && $this->consumeBreak()) {
                break;
            }
            if (!$indefinite && $remaining-- <= 0) {
                break;
            }

            $out[] = $this->readUntypedValue();
        }

        $this->depth--;

        return $out;
    }

    /**
     * Read an untyped CBOR map.
     *
     * @return array<string, mixed>
     */
    private function readUntypedMap(int $info): array
    {
        if (++$this->depth > self::MAX_DEPTH) {
            $this->malformed();
        }

        $indefinite = $info === self::INFO_INDEFINITE;
        $remaining = $indefinite ? -1 : $this->readLengthFromInfo($info);
        $out = [];

        while (true) {
            if ($indefinite && $this->consumeBreak()) {
                break;
            }
            if (!$indefinite && $remaining-- <= 0) {
                break;
            }

            $key = $this->readUntypedValue();
            $out[(string) $key] = $this->readUntypedValue();
        }

        $this->depth--;

        return $out;
    }

    /**
     * Consume a CBOR break byte if present.
     */
    private function consumeBreak(): bool
    {
        if ($this->idx < $this->length
            && \ord($this->payload[$this->idx]) === 0xFF
        ) {
            $this->idx++;
            return true;
        }

        return false;
    }

    /**
     * Assert that the payload has the requested number of bytes remaining.
     */
    private function ensureRemainingBytes(int $length): void
    {
        if ($this->idx + $length > $this->length) {
            $this->malformed();
        }
    }

    /**
     * Fail the cold malformed-input path.
     */
    private function malformed(): never
    {
        throw new \RuntimeException('Malformed CBOR payload.');
    }

    /**
     * Read a CBOR length from an additional-info value.
     */
    private function readLengthFromInfo(int $info): int
    {
        if ($info < self::INFO_ONE_BYTE) {
            return $info;
        }

        switch ($info) {
            case self::INFO_ONE_BYTE:
                $this->ensureRemainingBytes(1);
                return \ord($this->payload[$this->idx++]);

            case self::INFO_TWO_BYTES:
                $this->ensureRemainingBytes(2);
                $value = (\ord($this->payload[$this->idx]) << 8)
                    | \ord($this->payload[$this->idx + 1]);
                $this->idx += 2;
                return $value;

            case self::INFO_FOUR_BYTES:
                $this->ensureRemainingBytes(4);
                $idx = $this->idx;
                $this->idx = $idx + 4;
                return (\ord($this->payload[$idx]) << 24)
                    | (\ord($this->payload[$idx + 1]) << 16)
                    | (\ord($this->payload[$idx + 2]) << 8)
                    | \ord($this->payload[$idx + 3]);

            case self::INFO_EIGHT_BYTES:
                $this->ensureRemainingBytes(8);
                $idx = $this->idx;
                $this->idx = $idx + 8;
                $high = (\ord($this->payload[$idx]) << 24)
                    | (\ord($this->payload[$idx + 1]) << 16)
                    | (\ord($this->payload[$idx + 2]) << 8)
                    | \ord($this->payload[$idx + 3]);
                if ($high > 0x7FFF_FFFF) {
                    $this->malformed();
                }
                $low = (\ord($this->payload[$idx + 4]) << 24)
                    | (\ord($this->payload[$idx + 5]) << 16)
                    | (\ord($this->payload[$idx + 6]) << 8)
                    | \ord($this->payload[$idx + 7]);
                return ($high << 32) | $low;
        }

        $this->malformed();
    }

    /**
     * Skip a fixed-width length payload.
     *
     * @return void
     */
    private function skipLengthValue(int $info): void
    {
        if ($info < self::INFO_ONE_BYTE) {
            return;
        }

        $bytes = match ($info) {
            self::INFO_ONE_BYTE => 1,
            self::INFO_TWO_BYTES => 2,
            self::INFO_FOUR_BYTES => 4,
            self::INFO_EIGHT_BYTES => 8,
            default => $this->malformed(),
        };

        $this->ensureRemainingBytes($bytes);
        $this->idx += $bytes;
    }

}
