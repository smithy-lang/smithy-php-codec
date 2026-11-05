<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codec\FormUrlEncoded;

use Smithy\Codec\Exception\SerializationException;
use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\SerializableStructInterface;
use Smithy\Core\Serde\ShapeSerializerInterface;
use Smithy\Core\Serde\TimestampFormat;
use Smithy\Core\Shape\BigDecimal;
use Smithy\Core\Shape\BigInteger;
use Smithy\Core\Shape\Document\DocumentInterface;
use Smithy\Core\Shape\ShapeType;
use Smithy\Core\Shape\UnionInterface;

/**
 * Serialize awsQuery-family request bodies.
 *
 * Behavior is driven by {@see FormUrlEncodedCodecSettings}.
 *
 * @internal Instantiated only by {@see FormUrlEncodedCodec}.
 */
final class FormUrlEncodedSerializer implements ShapeSerializerInterface
{
    private readonly FormUrlEncodedCodecSettings $settings;
    private readonly bool $honorTimestampFormatTrait;
    private readonly bool $honorXmlName;
    private readonly bool $capitalizeResolvedWireNames;
    private readonly int $wireNameMode;
    /**
     * The accumulated URL-encoded body. Each `serializeXxx` scalar
     * append emits one `key=value&` fragment; {@see flush()} trims the
     * final trailing `&` before returning.
     */
    private string $buffer = '';
    /**
     * Prefix carried by every scalar emit under the current struct /
     * list / map / union frame. Always ends with `'.'` (or is the empty
     * string at the outermost frame), so appending the wire name is a
     * bare concat with no separator branch.
     */
    private string $prefix = '';
    /**
     * Wire-name lookup for the current struct / union frame, or `null`
     * at the outermost level before any `beginStruct` runs. Sourced from
     * {@see FormUrlEncodedSchemaExtension::$memberWireNamesByMode}.
     *
     * @var array<int, string>|null
     */
    private ?array $wireNames = null;
    /**
     * Precomputed timestamp-format map for the current struct frame:
     * `memberIndex => TimestampFormat`. Sparse. Only timestamp members
     * with an explicit `@timestampFormat` trait appear.
     *
     * @var array<int, TimestampFormat>
     */
    private array $tsFormats = [];
    /** Current nested structure depth. */
    private int $frameDepth = 0;

    /** @var array<int, string> Saved parent prefixes by depth. */
    private array $prefixStack = [];

    /** @var array<int, array<int, string>|null> Saved wire-name tables. */
    private array $wireNameStack = [];

    /** @var array<int, array<int, TimestampFormat>> Saved timestamp tables. */
    private array $timestampFormatStack = [];

    public function __construct(
        ?FormUrlEncodedCodecSettings $settings = null
    ) {
        $this->settings = $settings ?? new FormUrlEncodedCodecSettings();
        $this->honorTimestampFormatTrait =
            $this->settings->honorTimestampFormatTrait;
        $this->honorXmlName = $this->settings->honorXmlName;
        $this->capitalizeResolvedWireNames =
            $this->settings->capitalizeResolvedWireNames;
        $this->wireNameMode = match (true) {
            $this->honorXmlName
                && !$this->capitalizeResolvedWireNames
                    => FormUrlEncodedSchemaExtension::WIRE_MODE_RESOLVED,
            $this->honorXmlName
                && $this->capitalizeResolvedWireNames
                    => FormUrlEncodedSchemaExtension::
                        WIRE_MODE_CAPITALIZED_RESOLVED,
            !$this->capitalizeResolvedWireNames
                => FormUrlEncodedSchemaExtension::WIRE_MODE_RAW,
            default => FormUrlEncodedSchemaExtension::WIRE_MODE_CAPITALIZED_RAW
        };
    }

    // Each of these looks up the current wire name in one array offset,
    // concatenates the prefix + wire name + '=' + urlencoded value +
    // '&', and appends to the buffer in a single `.=`. The hot path for
    // a struct with N scalar members is N x (1 array read, 1 C-string
    // encode, 1 string concat).
    //
    // `$this->wireNames` is guaranteed non-null here because scalar
    // methods only run inside a beginStruct to endStruct pair emitted
    // by codegen. The `null` state is reserved for the outermost
    // "before any struct opens" case, which no scalar path reaches.

    /**
     * Serialize a boolean member.
     *
     * @return void
     */
    public function serializeBoolean(
        Schema $schema,
        bool $value,
        int $idx
    ): void
    {
        $this->buffer .= $this->prefix
            . $this->wireNames[$idx]
            . '='
            . ($value ? 'true' : 'false')
            . '&';
    }

    /**
     * Serialize an integer member.
     *
     * @return void
     */
    public function serializeInteger(Schema $schema, int $value, int $idx): void
    {
        $this->buffer .= $this->prefix
            . $this->wireNames[$idx]
            . '='
            . (string) $value
            . '&';
    }

    /**
     * Serialize a float member.
     *
     * @return void
     */
    public function serializeFloat(Schema $schema, float $value, int $idx): void
    {
        // Match Smithy's canonical non-numeric float spellings.
        $s = match ($str = (string) $value) {
            'NAN'  => 'NaN',
            'INF'  => 'Infinity',
            '-INF' => '-Infinity',
            default => $str
        };
        $this->buffer .= $this->prefix
            . $this->wireNames[$idx]
            . '='
            . \rawurlencode($s)
            . '&';
    }

    /**
     * Serialize a big-integer member.
     *
     * @return void
     */
    public function serializeBigInteger(
        Schema $schema,
        BigInteger $value,
        int $idx
    ): void
    {
        $this->buffer .= $this->prefix
            . $this->wireNames[$idx]
            . '='
            . \rawurlencode($value->value)
            . '&';
    }

    /**
     * Serialize a big-decimal member.
     *
     * @return void
     */
    public function serializeBigDecimal(
        Schema $schema,
        BigDecimal $value,
        int $idx
    ): void
    {
        $this->buffer .= $this->prefix
            . $this->wireNames[$idx]
            . '='
            . \rawurlencode($value->value)
            . '&';
    }

    /**
     * Serialize a string member.
     *
     * @return void
     */
    public function serializeString(
        Schema $schema,
        string $value,
        int $idx
    ): void
    {
        $this->buffer .= $this->prefix
            . $this->wireNames[$idx]
            . '='
            . \rawurlencode($value)
            . '&';
    }

    /**
     * Serialize a blob member.
     *
     * @return void
     */
    public function serializeBlob(Schema $schema, string $value, int $idx): void
    {
        $this->buffer .= $this->prefix
            . $this->wireNames[$idx]
            . '='
            . \rawurlencode(\base64_encode($value))
            . '&';
    }

    /**
     * Serialize a timestamp member.
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
        $fmt = ($this->honorTimestampFormatTrait
            ? ($this->tsFormats[$idx] ?? $format)
            : null
        ) ?? $this->settings->defaultTimestampFormat;
        $this->buffer .= $this->prefix
            . $this->wireNames[$idx]
            . '='
            . \rawurlencode($fmt->format($value))
            . '&';
    }

    /**
     * Serialize a list member.
     *
     * @return void
     */
    public function serializeList(Schema $schema, array $list, int $idx): void
    {
        if ($list === []) {
            if ($this->settings->alwaysFlattenLists) {
                return;
            }

            $containerName = $this->wireNames[$idx] ?? '';
            $this->buffer .= $this->prefix . $containerName . '=&';

            return;
        }

        static $extKey = null;
        $extKey ??= FormUrlEncodedSchemaExtensionProvider::key();
        /** @var FormUrlEncodedSchemaExtension|null $info */
        $info = $schema->getExtension($extKey);

        $containerName = $this->wireNames[$idx] ?? '';
        $precomputedItemPrefix = $this->selectMemberListPrefix($info);
        if ($precomputedItemPrefix !== null) {
            $itemPrefix = $this->prefix . $precomputedItemPrefix;
        } else {
            $flattened = $this->settings->alwaysFlattenLists
                || ($info?->flattened ?? false);
            if ($flattened) {
                $itemPrefix = $this->prefix . $containerName . '.';
            } else {
                $memberName = $this->honorXmlName
                    ? $info?->listMemberName ?? 'member'
                    : 'member';
                $itemPrefix = $this->prefix . $containerName . '.'
                    . $memberName . '.';
            }
        }

        $memberSchema = $info?->listMemberSchema ?? $schema->getListMember();
        // Resolve member type as its int discriminant once per call.
        $mt = $info?->listMemberType
            ?? $memberSchema?->type
            ?? ShapeType::Document->value;

        switch ($mt) {
            case ShapeType::String_->value:
            case ShapeType::Enum->value:
            case ShapeType::Integer->value:
            case ShapeType::Long->value:
            case ShapeType::Short->value:
            case ShapeType::Byte->value:
            case ShapeType::IntEnum->value:
            case ShapeType::Boolean->value:
            case ShapeType::Float_->value:
            case ShapeType::Double->value:
            case ShapeType::Blob->value:
            case ShapeType::Timestamp->value:
            case ShapeType::BigInteger->value:
            case ShapeType::BigDecimal->value:
                $fmt = $mt === ShapeType::Timestamp->value
                    ? (($this->honorTimestampFormatTrait
                        ? $info?->listMemberTimestampFormat
                        : null
                    ) ?? $this->settings->defaultTimestampFormat)
                    : null;
                $this->serializeScalarList(
                    $mt,
                    $list,
                    $itemPrefix,
                    $fmt
                );

                return;
        }

        $savedPrefix = $this->prefix;
        $savedWireNames = $this->wireNames;
        $this->prefix = $itemPrefix;
        $this->wireNames = [0 => ''];

        switch ($mt) {
            case ShapeType::Structure->value:
            case ShapeType::Union->value:
                $counter = 1;
                foreach ($list as $item) {
                    if ($item === null) {
                        $counter++;
                        continue;
                    }
                    $this->wireNames[0] = (string) $counter;
                    if ($mt === ShapeType::Structure->value) {
                        $this->serializeStruct($memberSchema, $item, 0);
                    } else {
                        $this->serializeUnion($memberSchema, $item, 0);
                    }
                    $counter++;
                }
                break;

            case ShapeType::List_->value:
                $counter = 1;
                foreach ($list as $item) {
                    if ($item === null) {
                        $counter++;
                        continue;
                    }
                    $this->wireNames[0] = (string) $counter;
                    $this->serializeList($memberSchema, $item, 0);
                    $counter++;
                }
                break;

            case ShapeType::Map->value:
                $counter = 1;
                foreach ($list as $item) {
                    if ($item === null) {
                        $counter++;
                        continue;
                    }
                    $this->wireNames[0] = (string) $counter;
                    $this->serializeMap($memberSchema, $item, 0);
                    $counter++;
                }
                break;
        }

        $this->prefix = $savedPrefix;
        $this->wireNames = $savedWireNames;
    }

    /**
     * Serialize a map member.
     *
     * @return void
     *
     * @throws SerializationException
     */
    public function serializeMap(Schema $schema, array $map, int $idx): void
    {
        if (!$this->settings->supportsMaps) {
            throw new SerializationException(
                'This form-urlencoded mode does not support map '
                . 'serialization (member: '
                . ($schema->memberName ?? $schema->id) . ')'
            );
        }

        if ($map === []) {
            return;
        }

        static $extKey = null;
        $extKey ??= FormUrlEncodedSchemaExtensionProvider::key();
        /** @var FormUrlEncodedSchemaExtension|null $info */
        $info = $schema->getExtension($extKey);

        $containerName = $this->wireNames[$idx] ?? '';
        $flattened = $info?->flattened ?? false;
        $keyName = $info?->mapKeyName ?? 'key';
        $valueName = $info?->mapValueName ?? 'value';
        $keyAssignment = $info?->mapKeyAssignment ?? ($keyName . '=');
        $valueAssignment = $info?->mapValueAssignment
            ?? ($valueName . '=');

        $precomputedEntryPrefix = $info?->memberMapEntryPrefix;
        if ($precomputedEntryPrefix !== null) {
            $baseEntryPrefix = $this->prefix . $precomputedEntryPrefix;
        } else {
            $baseEntryPrefix = $flattened
                ? $this->prefix . $containerName . '.'
                : $this->prefix . $containerName . '.entry.';
        }

        $valueSchema = $info?->mapValueSchema ?? $schema->getMapValue();
        $valueType = $info?->mapValueType
            ?? $valueSchema?->type
            ?? ShapeType::String_->value;

        switch ($valueType) {
            case ShapeType::String_->value:
            case ShapeType::Enum->value:
            case ShapeType::Integer->value:
            case ShapeType::Long->value:
            case ShapeType::Short->value:
            case ShapeType::Byte->value:
            case ShapeType::IntEnum->value:
            case ShapeType::Boolean->value:
            case ShapeType::Float_->value:
            case ShapeType::Double->value:
            case ShapeType::Timestamp->value:
            case ShapeType::Blob->value:
            case ShapeType::BigInteger->value:
            case ShapeType::BigDecimal->value:
                $format = $valueType === ShapeType::Timestamp->value
                    ? (($this->honorTimestampFormatTrait
                        ? $info?->mapValueTimestampFormat
                        : null
                    ) ?? $this->settings->defaultTimestampFormat)
                    : null;
                $this->serializeScalarMap(
                    $valueType,
                    $map,
                    $baseEntryPrefix,
                    $keyAssignment,
                    $valueAssignment,
                    $format
                );

                return;
        }

        $savedPrefix = $this->prefix;
        $savedWireNames = $this->wireNames;
        $this->wireNames = [0 => $valueName];

        $counter = 1;
        foreach ($map as $k => $v) {
            $entryPrefix = $baseEntryPrefix . $counter . '.';
            $keyString = (string) $k;

            $this->buffer .= $entryPrefix
                . $keyAssignment
                . \rawurlencode($keyString)
                . '&';

            if ($v === null) {
                $counter++;
                continue;
            }

            $this->prefix = $entryPrefix;
            switch ($valueType) {
                case ShapeType::Structure->value:
                case ShapeType::Union->value:
                    if ($valueType === ShapeType::Structure->value) {
                        $this->serializeStruct($valueSchema, $v, 0);
                    } else {
                        $this->serializeUnion($valueSchema, $v, 0);
                    }
                    break;
                case ShapeType::List_->value:
                    $this->serializeList($valueSchema, $v, 0);
                    break;
                case ShapeType::Map->value:
                    $this->serializeMap($valueSchema, $v, 0);
                    break;
            }

            $counter++;
        }

        $this->prefix = $savedPrefix;
        $this->wireNames = $savedWireNames;
    }

    /**
     * Serialize a structure member.
     *
     * @return void
     */
    public function serializeStruct(
        Schema $schema,
        SerializableStructInterface $struct,
        int $idx
    ): void
    {
        $this->beginStruct($struct->getSchema(), $idx);
        $struct->serializeMembers($this);
        $this->endStruct();
    }

    /**
     * Initialize the top-level request frame.
     *
     * @return void
     */
    public function beginRootStruct(Schema $schema): void
    {
        static $extKey = null;
        $extKey ??= FormUrlEncodedSchemaExtensionProvider::key();
        /** @var FormUrlEncodedSchemaExtension|null $info */
        $info = $schema->getExtension($extKey);

        $this->prefix = '';
        $this->wireNames =
            $info?->memberWireNamesByMode[$this->wireNameMode] ?? [];
        $this->tsFormats = $info?->tsFormats ?? [];
    }

    /**
     * Push a structure frame.
     *
     * @return void
     */
    public function beginStruct(Schema $schema, int $idx): void
    {
        static $extKey = null;
        $extKey ??= FormUrlEncodedSchemaExtensionProvider::key();
        /** @var FormUrlEncodedSchemaExtension|null $info */
        $info = $schema->getExtension($extKey);

        $depth = $this->frameDepth++;
        $this->prefixStack[$depth] = $this->prefix;
        $this->wireNameStack[$depth] = $this->wireNames;
        $this->timestampFormatStack[$depth] = $this->tsFormats;

        if ($this->wireNames !== null) {
            $this->prefix .= $this->wireNames[$idx] . '.';
        }

        $this->wireNames =
            $info?->memberWireNamesByMode[$this->wireNameMode] ?? [];
        $this->tsFormats = $info?->tsFormats ?? [];
    }

    /**
     * Pop a structure frame.
     *
     * @return void
     */
    public function endStruct(): void
    {
        $depth = --$this->frameDepth;
        $this->prefix = $this->prefixStack[$depth];
        $this->wireNames = $this->wireNameStack[$depth];
        $this->tsFormats = $this->timestampFormatStack[$depth];
    }

    /**
     * Serialize a union member.
     *
     * @return void
     */
    public function serializeUnion(Schema $schema, mixed $union, int $idx): void
    {
        /** @var UnionInterface $union */
        $value = $union->value();
        if ($value === null) {
            return;
        }

        static $extKey = null;
        $extKey ??= FormUrlEncodedSchemaExtensionProvider::key();
        /** @var FormUrlEncodedSchemaExtension|null $info */
        $info = $union->getSchema()->getExtension($extKey);
        $entry = $info?->unionEncodeByMemberIndex[
            $union->getMemberIndex()
        ] ?? null;
        if ($entry !== null) {
            $variantSchema = $entry[0];
            $variantWire = $entry[1][$this->wireNameMode];
            $variantTsFmt = $entry[2];
        } else {
            $tag = $union->tag();
            $variantSchema = $info?->unionMembersByName[$tag] ?? null;
            $variantWire = $variantSchema === null
                ? null
                : $this->resolveUnionVariantWireName(
                    $variantSchema,
                    $info,
                    $tag
                );
            $variantTsFmt = $info?->unionMemberTsFormats[$tag] ?? null;
        }
        if ($variantSchema === null) {
            return;
        }

        $savedPrefix = $this->prefix;
        if ($this->wireNames !== null) {
            $this->prefix .= $this->wireNames[$idx] . '.';
        }
        $savedWireNames = $this->wireNames;
        $savedTsFormats = $this->tsFormats;
        $variantIdx = $variantSchema->memberIndex;
        $this->wireNames = [$variantIdx => $variantWire];
        $this->tsFormats = [];
        if ($variantTsFmt !== null) {
            $this->tsFormats[$variantIdx] = $variantTsFmt;
        }

        $key = $this->prefix . $variantWire;
        switch ($variantSchema->type) {
            case ShapeType::String_->value:
            case ShapeType::Enum->value:
            case ShapeType::Integer->value:
            case ShapeType::Long->value:
            case ShapeType::Short->value:
            case ShapeType::Byte->value:
            case ShapeType::IntEnum->value:
            case ShapeType::Float_->value:
            case ShapeType::Double->value:
            case ShapeType::Boolean->value:
            case ShapeType::Timestamp->value:
            case ShapeType::Blob->value:
            case ShapeType::BigInteger->value:
            case ShapeType::BigDecimal->value:
                $this->serializeValue(
                    $variantSchema->type,
                    $value,
                    $key,
                    $variantTsFmt
                );
                break;
            case ShapeType::Structure->value:
                $this->serializeStruct($variantSchema, $value, $variantIdx);
                break;
            case ShapeType::Union->value:
                $this->serializeUnion($variantSchema, $value, $variantIdx);
                break;
            case ShapeType::List_->value:
                $this->serializeList($variantSchema, $value, $variantIdx);
                break;
            case ShapeType::Map->value:
                $this->serializeMap($variantSchema, $value, $variantIdx);
                break;
        }

        $this->prefix = $savedPrefix;
        $this->wireNames = $savedWireNames;
        $this->tsFormats = $savedTsFormats;
    }

    /**
     * Reject document serialization.
     *
     * @return void
     *
     * @throws SerializationException
     */
    public function serializeDocument(
        Schema $schema,
        ?DocumentInterface $value,
        int $idx
    ): void
    {
        throw new SerializationException(
            'This form-urlencoded mode does not support document '
            . 'types (member: '
            . ($schema->memberName ?? $schema->id) . ')'
        );
    }

    /**
     * Reset serializer state.
     *
     * @return void
     *
     * @internal Called by {@see FormUrlEncodedCodec} only.
     */
    public function reset(): void
    {
        $this->buffer = '';
        $this->prefix = '';
        $this->wireNames = null;
        $this->tsFormats = [];
        $this->frameDepth = 0;
        $this->prefixStack = [];
        $this->wireNameStack = [];
        $this->timestampFormatStack = [];
    }

    /**
     * Return the accumulated body and reset the buffer.
     */
    public function flush(): string
    {
        $out = $this->buffer !== ''
            ? \substr($this->buffer, 0, -1)
            : '';
        $this->reset();

        return $out;
    }

    /**
     * Serialize a list whose member type is scalar.
     *
     * @return void
     */
    private function serializeScalarList(
        int $type,
        array $list,
        string $itemPrefix,
        ?TimestampFormat $timestampFormat
    ): void
    {
        switch ($type) {
            case ShapeType::String_->value:
            case ShapeType::Enum->value:
                $counter = 1;
                foreach ($list as $item) {
                    if ($item !== null) {
                        $this->buffer .= $itemPrefix . $counter . '='
                            . \rawurlencode((string) $item) . '&';
                    }
                    $counter++;
                }

                return;

            case ShapeType::Integer->value:
            case ShapeType::Long->value:
            case ShapeType::Short->value:
            case ShapeType::Byte->value:
            case ShapeType::IntEnum->value:
                $counter = 1;
                foreach ($list as $item) {
                    if ($item !== null) {
                        $this->buffer .= $itemPrefix . $counter . '='
                            . (int) $item . '&';
                    }
                    $counter++;
                }

                return;

            case ShapeType::Boolean->value:
                $counter = 1;
                foreach ($list as $item) {
                    if ($item !== null) {
                        $this->buffer .= $itemPrefix . $counter . '='
                            . ($item ? 'true' : 'false') . '&';
                    }
                    $counter++;
                }

                return;

            case ShapeType::Float_->value:
            case ShapeType::Double->value:
                $counter = 1;
                foreach ($list as $item) {
                    if ($item !== null) {
                        $number = match ($str = (string) (float) $item) {
                            'NAN' => 'NaN',
                            'INF' => 'Infinity',
                            '-INF' => '-Infinity',
                            default => $str
                        };
                        $this->buffer .= $itemPrefix . $counter . '='
                            . \rawurlencode($number) . '&';
                    }
                    $counter++;
                }

                return;

            case ShapeType::Timestamp->value:
                $counter = 1;
                foreach ($list as $item) {
                    if ($item !== null) {
                        $this->buffer .= $itemPrefix . $counter . '='
                            . \rawurlencode(
                                $timestampFormat->format((float) $item)
                            )
                            . '&';
                    }
                    $counter++;
                }

                return;

            case ShapeType::Blob->value:
                $counter = 1;
                foreach ($list as $item) {
                    if ($item !== null) {
                        $this->buffer .= $itemPrefix . $counter . '='
                            . \rawurlencode(\base64_encode((string) $item))
                            . '&';
                    }
                    $counter++;
                }

                return;

            case ShapeType::BigInteger->value:
            case ShapeType::BigDecimal->value:
                $counter = 1;
                foreach ($list as $item) {
                    if ($item !== null) {
                        $this->buffer .= $itemPrefix . $counter . '='
                            . \rawurlencode($item->value) . '&';
                    }
                    $counter++;
                }

                return;
        }
    }

    /**
     * Serialize a map whose value type is scalar.
     *
     * @return void
     */
    private function serializeScalarMap(
        int $type,
        array $map,
        string $entryPrefix,
        string $keyAssignment,
        string $valueAssignment,
        ?TimestampFormat $timestampFormat
    ): void
    {
        switch ($type) {
            case ShapeType::String_->value:
            case ShapeType::Enum->value:
                $counter = 1;
                foreach ($map as $key => $value) {
                    $prefix = $entryPrefix . $counter . '.';
                    $this->buffer .= $prefix . $keyAssignment
                        . \rawurlencode((string) $key) . '&';
                    if ($value !== null) {
                        $this->buffer .= $prefix . $valueAssignment
                            . \rawurlencode((string) $value) . '&';
                    }
                    $counter++;
                }

                return;

            case ShapeType::Integer->value:
            case ShapeType::Long->value:
            case ShapeType::Short->value:
            case ShapeType::Byte->value:
            case ShapeType::IntEnum->value:
                $counter = 1;
                foreach ($map as $key => $value) {
                    $prefix = $entryPrefix . $counter . '.';
                    $this->buffer .= $prefix . $keyAssignment
                        . \rawurlencode((string) $key) . '&';
                    if ($value !== null) {
                        $this->buffer .= $prefix . $valueAssignment
                            . (int) $value . '&';
                    }
                    $counter++;
                }

                return;

            case ShapeType::Boolean->value:
                $counter = 1;
                foreach ($map as $key => $value) {
                    $prefix = $entryPrefix . $counter . '.';
                    $this->buffer .= $prefix . $keyAssignment
                        . \rawurlencode((string) $key) . '&';
                    if ($value !== null) {
                        $this->buffer .= $prefix . $valueAssignment
                            . ($value ? 'true' : 'false') . '&';
                    }
                    $counter++;
                }

                return;

            case ShapeType::Float_->value:
            case ShapeType::Double->value:
                $counter = 1;
                foreach ($map as $key => $value) {
                    $prefix = $entryPrefix . $counter . '.';
                    $this->buffer .= $prefix . $keyAssignment
                        . \rawurlencode((string) $key) . '&';
                    if ($value !== null) {
                        $number = match ($str = (string) (float) $value) {
                            'NAN' => 'NaN',
                            'INF' => 'Infinity',
                            '-INF' => '-Infinity',
                            default => $str
                        };
                        $this->buffer .= $prefix . $valueAssignment
                            . \rawurlencode($number) . '&';
                    }
                    $counter++;
                }

                return;

            case ShapeType::Timestamp->value:
                $counter = 1;
                foreach ($map as $key => $value) {
                    $prefix = $entryPrefix . $counter . '.';
                    $this->buffer .= $prefix . $keyAssignment
                        . \rawurlencode((string) $key) . '&';
                    if ($value !== null) {
                        $this->buffer .= $prefix . $valueAssignment
                            . \rawurlencode(
                                $timestampFormat->format((float) $value)
                            )
                            . '&';
                    }
                    $counter++;
                }

                return;

            case ShapeType::Blob->value:
                $counter = 1;
                foreach ($map as $key => $value) {
                    $prefix = $entryPrefix . $counter . '.';
                    $this->buffer .= $prefix . $keyAssignment
                        . \rawurlencode((string) $key) . '&';
                    if ($value !== null) {
                        $this->buffer .= $prefix . $valueAssignment
                            . \rawurlencode(
                                \base64_encode((string) $value)
                            )
                            . '&';
                    }
                    $counter++;
                }

                return;

            case ShapeType::BigInteger->value:
            case ShapeType::BigDecimal->value:
                $counter = 1;
                foreach ($map as $key => $value) {
                    $prefix = $entryPrefix . $counter . '.';
                    $this->buffer .= $prefix . $keyAssignment
                        . \rawurlencode((string) $key) . '&';
                    if ($value !== null) {
                        $this->buffer .= $prefix . $valueAssignment
                            . \rawurlencode($value->value) . '&';
                    }
                    $counter++;
                }

                return;
        }
    }

    /**
     * Serialize one scalar-like value at the given wire key.
     */
    private function serializeValue(
        int $type,
        mixed $value,
        string $key,
        ?TimestampFormat $timestampFormat = null
    ): void
    {
        switch ($type) {
            case ShapeType::String_->value:
            case ShapeType::Enum->value:
                $this->buffer .= $key
                    . '='
                    . \rawurlencode((string) $value)
                    . '&';

                return;
            case ShapeType::Integer->value:
            case ShapeType::Long->value:
            case ShapeType::Short->value:
            case ShapeType::Byte->value:
            case ShapeType::IntEnum->value:
                $this->buffer .= $key . '=' . (int) $value . '&';

                return;
            case ShapeType::Boolean->value:
                $this->buffer .= $key . '='
                    . ($value ? 'true' : 'false')
                    . '&';

                return;
            case ShapeType::Float_->value:
            case ShapeType::Double->value:
                $number = match ($str = (string) (float) $value) {
                    'NAN'  => 'NaN',
                    'INF'  => 'Infinity',
                    '-INF' => '-Infinity',
                    default => $str
                };
                $this->buffer .= $key . '=' . \rawurlencode($number) . '&';

                return;
            case ShapeType::Timestamp->value:
                $fmt = ($this->honorTimestampFormatTrait
                    ? $timestampFormat
                    : null
                ) ?? $this->settings->defaultTimestampFormat;
                $this->buffer .= $key . '='
                    . \rawurlencode($fmt->format((float) $value))
                    . '&';

                return;
            case ShapeType::Blob->value:
                $this->buffer .= $key . '='
                    . \rawurlencode(\base64_encode((string) $value))
                    . '&';

                return;
            case ShapeType::BigInteger->value:
            case ShapeType::BigDecimal->value:
                $this->buffer .= $key . '='
                    . \rawurlencode($value->value)
                    . '&';

                return;
        }

        throw new SerializationException(
            'serializeValue does not support ShapeType ' . $type
        );
    }

    private function selectMemberListPrefix(
        ?FormUrlEncodedSchemaExtension $info
    ): ?string
    {
        if (!$this->honorXmlName) {
            return null;
        }

        return $this->capitalizeResolvedWireNames
            ? $info?->capitalizedResolvedMemberListPrefix
            : $info?->resolvedMemberListPrefix;
    }

    private function resolveUnionVariantWireName(
        Schema $variantSchema,
        ?FormUrlEncodedSchemaExtension $info,
        string $tag
    ): string
    {
        if ($this->honorXmlName) {
            return $this->capitalizeResolvedWireNames
                ? (($info?->capitalizedResolvedUnionMemberWireNames[
                    $tag
                ] ?? null)
                    ?? $variantSchema->memberName)
                : (($info?->resolvedUnionMemberWireNames[$tag] ?? null)
                    ?? $variantSchema->memberName);
        }

        $name = (string) $variantSchema->memberName;
        if ($this->capitalizeResolvedWireNames) {
            $name = \ucfirst($name);
        }

        return \rawurlencode($name);
    }
}
