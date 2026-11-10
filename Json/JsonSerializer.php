<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codec\Json;

use Smithy\Codec\Exception\SerializationException;
use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\SerializableStructInterface;
use Smithy\Core\Serde\ShapeSerializerInterface;
use Smithy\Core\Shape\ShapeType;
use Smithy\Core\Serde\TimestampFormat;
use Smithy\Core\Shape\BigDecimal;
use Smithy\Core\Shape\BigInteger;
use Smithy\Core\Shape\Document\DocumentInterface;
use Smithy\Core\Shape\UnionInterface;

/**
 * Serialize Smithy shapes into JSON-compatible PHP values.
 */
final class JsonSerializer implements ShapeSerializerInterface
{
    private readonly JsonCodecSettings $settings;
    private readonly bool $honorTimestampFormatTrait;
    private readonly bool $useStringForArbitraryPrecision;
    /**
     * Root output for the current payload.
     */
    private mixed $root = null;
    /**
     * Active struct frame.
     *
     * @var array<string, mixed>|null
     */
    private ?array $current = null;
    /**
     * Wire-name lookup for the current struct/union frame.
     *
     * @var array<int, string>|null
     */
    private ?array $wireNames = null;
    /**
     * Timestamp formats for the active struct frame.
     *
     * @var array<int, TimestampFormat>|null
     */
    private ?array $timestampFormats = null;
    /**
     * Saved parent frames for nesting. Each entry is
     * `[parentFrameByValue, parentWireNames, parentTimestampFormats, idx]`
     * where `$idx` is the member-index argument of the matching
     * `beginStruct` call. `endStruct` pops and assigns the just-finished
     * frame back into the parent at that `$idx`'s wire name.
     *
     * @var list<array{
     *     0: array|null,
     *     1: array<int, string>|null,
     *     2: array<int, TimestampFormat>|null,
     *     3: int
     * }>
     */
    private array $frameStack = [];
    /**
     * Whether the current payload may contain a BigNumberToken.
     */
    private bool $rootHasBigNumber = false;
    /**
     * Reused sub-serializer for nested document values.
     */
    private ?self $documentSerializer = null;

    public function __construct(?JsonCodecSettings $settings = null)
    {
        $this->settings = $settings ?? new JsonCodecSettings();
        $this->honorTimestampFormatTrait =
            $this->settings->honorTimestampFormatTrait;
        $this->useStringForArbitraryPrecision =
            $this->settings->useStringForArbitraryPrecision;
    }

    /**
     * Serialize a boolean member into the current JSON frame.
     *
     * @return void
     */
    public function serializeBoolean(Schema $schema, bool $value, int $idx): void
    {
        $this->emit($value, $idx);
    }

    /**
     * Serialize an integer member into the current JSON frame.
     *
     * @return void
     */
    public function serializeInteger(Schema $schema, int $value, int $idx): void
    {
        $this->emit($value, $idx);
    }

    /**
     * Serialize a floating-point member into the current JSON frame.
     *
     * @return void
     */
    public function serializeFloat(Schema $schema, float $value, int $idx): void
    {
        // JSON renders non-finite floats as Smithy sentinel strings.
        $value = match ((string) $value) {
            'NAN'  => 'NaN',
            'INF'  => 'Infinity',
            '-INF' => '-Infinity',
            default => $value,
        };
        $this->emit($value, $idx);
    }

    /**
     * Serialize a big-integer member into the current JSON frame.
     *
     * @return void
     */
    public function serializeBigInteger(Schema $schema, BigInteger $value, int $idx): void
    {
        $this->emit($this->renderBigInteger($value), $idx);
    }

    /**
     * Serialize a big-decimal member into the current JSON frame.
     *
     * @return void
     */
    public function serializeBigDecimal(Schema $schema, BigDecimal $value, int $idx): void
    {
        $this->emit($this->renderBigDecimal($value), $idx);
    }

    /**
     * Serialize a string member into the current JSON frame.
     *
     * @return void
     */
    public function serializeString(Schema $schema, string $value, int $idx): void
    {
        $this->emit($value, $idx);
    }

    /**
     * Serialize a blob member into the current JSON frame.
     *
     * @return void
     */
    public function serializeBlob(Schema $schema, string $value, int $idx): void
    {
        // JSON blobs are base64-encoded strings.
        $this->emit(\base64_encode($value), $idx);
    }

    /**
     * Serialize a timestamp member into the current JSON frame.
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
        // Prefer the frame's precomputed member format.
        $resolved = ($this->honorTimestampFormatTrait
            ? ($this->timestampFormats[$idx] ?? $format)
            : null
        ) ?? $this->settings->defaultTimestampFormat;

        if ($resolved === TimestampFormat::EpochSeconds) {
            $this->emit($value, $idx);
            return;
        }
        $this->emit($resolved->format($value), $idx);
    }

    /**
     * Serialize a list member into the current JSON frame.
     *
     * @return void
     */
    public function serializeList(
        Schema $schema,
        array $list,
        int $idx
    ): void
    {
        // Read sparse handling from the extension once.
        static $extKey = null;
        $extKey ??= JsonSchemaExtensionProvider::key();
        $memberSchema = $schema->listMember;
        /** @var JsonSchemaExtension|null $info */
        $info = $schema->getExtension($extKey);
        $sparse = $info?->sparse ?? false;

        if ($memberSchema === null) {
            $this->emit($list, $idx);
            return;
        }

        $type = $info?->listMemberType
            ?? $memberSchema->type
            ?? ShapeType::Document->value;
        $context = $type === ShapeType::Timestamp->value
            ? $info?->listMemberTimestampFormat
            : $memberSchema;
        $out = [];
        foreach ($list as $item) {
            if ($item === null) {
                if ($sparse) {
                    $out[] = null;
                }
                continue;
            }
            $out[] = $this->renderValue($type, $item, $context);
        }
        $this->emit($out, $idx);
    }


    /**
     * Serialize a map member into the current JSON frame.
     *
     * @return void
     */
    public function serializeMap(Schema $schema, array $map, int $idx): void
    {
        // Read sparse handling from the extension once.
        static $extKey = null;
        $extKey ??= JsonSchemaExtensionProvider::key();
        $valueSchema = $schema->mapValue;
        /** @var JsonSchemaExtension|null $info */
        $info = $schema->getExtension($extKey);
        $sparse = $info?->sparse ?? false;

        $out = [];
        if ($valueSchema === null) {
            foreach ($map as $key => $value) {
                $stringKey = (string) $key;
                if ($value === null) {
                    if ($sparse) {
                        $out[$stringKey] = null;
                    }
                    continue;
                }
                $out[$stringKey] = $value;
            }
        } else {
            $type = $info?->mapValueType ?? $valueSchema->type;
            $context = $type === ShapeType::Timestamp->value
                ? $info?->mapValueTimestampFormat
                : $valueSchema;
            foreach ($map as $key => $value) {
                $stringKey = (string) $key;
                if ($value === null) {
                    if ($sparse) {
                        $out[$stringKey] = null;
                    }
                    continue;
                }

                $out[$stringKey] = $this->renderValue(
                    $type,
                    $value,
                    $context
                );
            }
        }
        // Empty maps must render as `{}`.
        if ($out === []) {
            $this->emit(new \stdClass(), $idx);
            return;
        }
        $this->emit($out, $idx);
    }


    /**
     * Serialize a structure member into the current JSON frame.
     *
     * @return void
     */
    public function serializeStruct(
        Schema $schema,
        SerializableStructInterface $struct,
        int $idx
    ): void
    {
        // Serialize against the struct's canonical schema.
        $this->beginStruct($struct->getSchema(), $idx);
        $struct->serializeMembers($this);
        $this->endStruct();
    }

    /**
     * Begin a structure frame for indexed member writes.
     *
     * @return void
     */
    public function beginStruct(Schema $schema, int $idx): void
    {
        // Cache the extension key in the hot path.
        static $extKey = null;
        $extKey ??= JsonSchemaExtensionProvider::key();
        /** @var JsonSchemaExtension|null $info */
        $info = $schema->getExtension($extKey);

        // Capture the top-level big-number flag once.
        if ($this->frameStack === []) {
            $this->rootHasBigNumber =
                !$this->useStringForArbitraryPrecision
                && ($info?->hasBigNumber ?? false);
        }

        // Save the parent frame before rebinding `$current`.
        $this->frameStack[] = [
            $this->current,
            $this->wireNames,
            $this->timestampFormats,
            $idx,
        ];

        $newFrame = [];
        $this->current = &$newFrame;
        // Pick the wire-name table once per struct.
        $this->wireNames = $this->settings->honorJsonName
            ? $info?->wireNameByMemberIndex
            : $info?->rawWireNameByMemberIndex;
        $this->timestampFormats = $info?->timestampFormats ?? [];
    }

    /**
     * End the current structure frame.
     *
     * @return void
     */
    public function endStruct(): void
    {
        $finishedFrame = $this->current;

        [$parentFrame, $parentWireNames, $parentTimestampFormats, $idx]
            = \array_pop($this->frameStack);

        // Drop the old reference before rebinding `$current`.
        unset($this->current);

        $this->wireNames = $parentWireNames;
        $this->timestampFormats = $parentTimestampFormats;

        if ($parentWireNames === null) {
            // Empty structs must render as `{}`.
            $this->root = $finishedFrame === []
                ? new \stdClass()
                : $finishedFrame;
            $this->current = null;
            return;
        }

        // Nested empty structs also render as `{}`.
        $this->current = &$parentFrame;
        $key = $parentWireNames[$idx];
        $this->current[$key] = $finishedFrame === []
            ? new \stdClass()
            : $finishedFrame;
    }

    /**
     * Serialize a union member into the current JSON frame.
     *
     * @return void
     */
    public function serializeUnion(Schema $schema, mixed $union, int $idx): void
    {
        static $extKey = null;
        $extKey ??= JsonSchemaExtensionProvider::key();
        /** @var JsonSchemaExtension|null $info */
        $info = $schema->getExtension($extKey);

        $this->emit($this->renderUnion($info ?? $schema, $union), $idx);
    }

    /**
     * Serialize a document member into the current JSON frame.
     *
     * @return void
     */
    public function serializeDocument(
        Schema $schema,
        ?DocumentInterface $value,
        int $idx
    ): void {
        if ($value === null) {
            $this->emit(null, $idx);
            return;
        }
        // Document shapes reuse the normal serializeXxx() paths.
        $value->serialize($this, $idx);
    }

    /**
     * Reset this serializer for reuse.
     *
     * @internal Called by {@see JsonCodec} and {@see JsonSerializer}.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->root = null;
        // Release any frame reference before clearing state.
        unset($this->current);
        $this->current = null;
        $this->wireNames = null;
        $this->timestampFormats = null;
        $this->frameStack = [];
        $this->rootHasBigNumber = false;
    }

    /**
     * Flush the encoded JSON payload.
     */
    public function flush(): string
    {
        $encoded = \json_encode(
            $this->root,
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE
        );
        if ($encoded === false) {
            throw new SerializationException(
                'JSON serialization failed: ' . \json_last_error_msg()
            );
        }
        // Unwrap big-number placeholders only when the payload uses them.
        if ($this->rootHasBigNumber) {
            $pattern = '/"'
                . \preg_quote(BigNumberToken::ENCODED_PREFIX, '/')
                . '(-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?)"/';
            $encoded = \preg_replace_callback(
                $pattern,
                static fn(array $m): string => $m[1],
                $encoded
            );
            if ($encoded === null) {
                throw new SerializationException(
                    'JSON serialization failed while unwrapping big numbers.'
                );
            }
        }
        // Keep the instance reusable after flush().
        $this->root = null;
        $this->rootHasBigNumber = false;
        return $encoded;
    }

    /**
     * Write a member value to the active frame or root.
     */
    private function emit(mixed $value, int $idx): void
    {
        if ($this->wireNames !== null) {
            $this->current[$this->wireNames[$idx]] = $value;
            return;
        }
        $this->root = $value;
    }

    private function renderStruct(Schema $memberSchema, mixed $value): array|\stdClass
    {
        // Cache the extension key in the hot path.
        static $extKey = null;
        $extKey ??= JsonSchemaExtensionProvider::key();
        /** @var SerializableStructInterface $value */
        $structSchema = $value->getSchema();
        /** @var JsonSchemaExtension $info */
        $info = $structSchema->getExtension($extKey);

        // Preserve the parent's live frame reference across inline writes.
        $prevCurrent = &$this->current;
        $prevWireNames = $this->wireNames;
        $prevTsFormats = $this->timestampFormats;

        $frame = [];
        unset($this->current);
        $this->current = &$frame;
        // Pick the wire-name table once per struct.
        $this->wireNames = $this->settings->honorJsonName
            ? $info->wireNameByMemberIndex
            : $info->rawWireNameByMemberIndex;
        $this->timestampFormats = $info->timestampFormats;

        $value->serializeMembers($this);

        // Read from `$this->current` in case nested imperative writes rebound it.
        $result = $this->current;

        unset($this->current);
        $this->current = &$prevCurrent;
        $this->wireNames = $prevWireNames;
        $this->timestampFormats = $prevTsFormats;

        return $result === [] ? new \stdClass() : $result;
    }

    private function renderUnion(
        Schema|JsonSchemaExtension $context,
        mixed $value
    ): array
    {
        static $extKey = null;
        $extKey ??= JsonSchemaExtensionProvider::key();

        if ($context instanceof JsonSchemaExtension) {
            $info = $context;
        } else {
            /** @var JsonSchemaExtension|null $info */
            $info = $context->getExtension($extKey);
        }

        if (\is_array($value)) {
            $unionEncodeByMemberName = $info === null
                ? []
                : ($this->settings->honorJsonName
                    ? $info->unionEncodeByMemberName
                    : $info->rawUnionEncodeByMemberName);

            // Null entries represent absent variants.
            foreach ($value as $tag => $variantValue) {
                if ($variantValue === null) {
                    continue;
                }
                $tag = (string) $tag;
                $entry = $unionEncodeByMemberName[$tag] ?? null;
                if ($entry === null) {
                    return [$tag => $variantValue];
                }

                return [$entry[0] => $this->renderValue(
                    $entry[1],
                    $variantValue,
                    $entry[2] ?? null
                )];
            }
            return [];
        }

        /** @var UnionInterface $value */
        $memberIndex = $value->getMemberIndex();
        $entry = $info === null || $memberIndex < 0
            ? null
            : ($this->settings->honorJsonName
                ? $info->unionEncodeByMemberIndex
                : $info->rawUnionEncodeByMemberIndex)[$memberIndex] ?? null;
        $variantValue = $value->value();

        if ($entry !== null) {
            return [$entry[0] => $this->renderValue(
                $entry[1],
                $variantValue,
                $entry[2] ?? null
            )];
        }

        $tag = $value->tag();
        $entry = $info === null
            ? null
            : ($this->settings->honorJsonName
                ? $info->unionEncodeByMemberName
                : $info->rawUnionEncodeByMemberName)[$tag] ?? null;

        return $entry === null
            ? [$tag => $variantValue]
            : [$entry[0] => $this->renderValue(
                $entry[1],
                $variantValue,
                $entry[2] ?? null
            )];
    }


    private function renderListInline(Schema $memberSchema, array $items): array
    {
        // Read list metadata from the extension once.
        static $extKey = null;
        $extKey ??= JsonSchemaExtensionProvider::key();
        $itemSchema = $memberSchema->listMember;
        /** @var JsonSchemaExtension|null $info */
        $info = $memberSchema->getExtension($extKey);
        $sparse = $info?->sparse ?? false;
        $type = $info?->listMemberType ?? $itemSchema?->type;

        $out = [];
        if ($itemSchema === null || $type === null) {
            foreach ($items as $item) {
                if ($item === null) {
                    if ($sparse) {
                        $out[] = null;
                    }
                    continue;
                }
                $out[] = $item;
            }
            return $out;
        }

        $context = $type === ShapeType::Timestamp->value
            ? $info?->listMemberTimestampFormat
            : ($type === ShapeType::Union->value
                ? $info?->listMemberUnionInfo ?? $itemSchema
                : $itemSchema);
        foreach ($items as $item) {
            if ($item === null) {
                if ($sparse) {
                    $out[] = null;
                }
                continue;
            }
            $out[] = $this->renderValue($type, $item, $context);
        }
        return $out;
    }

    private function renderMapInline(Schema $memberSchema, array $entries): array|\stdClass
    {
        // Read map metadata from the extension once.
        static $extKey = null;
        $extKey ??= JsonSchemaExtensionProvider::key();
        $valueSchema = $memberSchema->mapValue;
        /** @var JsonSchemaExtension|null $info */
        $info = $memberSchema->getExtension($extKey);
        $sparse = $info?->sparse ?? false;
        $out = [];

        if ($valueSchema === null) {
            foreach ($entries as $key => $value) {
                $stringKey = (string) $key;
                if ($value === null) {
                    if ($sparse) {
                        $out[$stringKey] = null;
                    }
                    continue;
                }
                $out[$stringKey] = $value;
            }
            return $out === [] ? new \stdClass() : $out;
        }

        $type = $info?->mapValueType ?? $valueSchema->type;
        $context = $type === ShapeType::Timestamp->value
            ? $info?->mapValueTimestampFormat
            : ($type === ShapeType::Union->value
                ? $info?->mapValueUnionInfo ?? $valueSchema
                : $valueSchema);
        foreach ($entries as $key => $value) {
            $stringKey = (string) $key;
            if ($value === null) {
                if ($sparse) {
                    $out[$stringKey] = null;
                }
                continue;
            }
            $out[$stringKey] = $this->renderValue(
                $type,
                $value,
                $context
            );
        }
        return $out === [] ? new \stdClass() : $out;
    }

    private function renderTimestamp(Schema $memberSchema, mixed $value): mixed
    {
        // Use the precomputed member timestamp format.
        static $extKey = null;
        $extKey ??= JsonSchemaExtensionProvider::key();
        /** @var JsonSchemaExtension|null $info */
        $info = $memberSchema->getExtension($extKey);
        $resolved = ($this->honorTimestampFormatTrait
            ? $info?->memberTimestampFormat
            : null
        ) ?? $this->settings->defaultTimestampFormat;

        $epoch = $value;

        if ($value instanceof \DateTimeInterface) {
            $epoch = (float) $value->format('U.u');
            if ($epoch == (int) $epoch) {
                $epoch = (int) $epoch;
            }
        }

        if ($resolved === TimestampFormat::EpochSeconds) {
            return $epoch;
        }
        return $resolved->format($epoch);
    }

    /**
     * Render one value using ShapeType-directed dispatch.
     */
    private function renderValue(int $type, mixed $value, mixed $context = null): mixed
    {
        switch ($type) {
            case ShapeType::String_->value:
                return (string) $value;

            case ShapeType::Enum->value:
                return $value instanceof \Smithy\Core\Shape\EnumInterface
                    ? $value->getValue()
                    : (string) $value;

            case ShapeType::Boolean->value:
                return (bool) $value;

            case ShapeType::Integer->value:
            case ShapeType::Long->value:
            case ShapeType::Short->value:
            case ShapeType::Byte->value:
                return (int) $value;

            case ShapeType::IntEnum->value:
                return $value instanceof \Smithy\Core\Shape\EnumInterface
                    ? $value->getValue()
                    : (int) $value;

            case ShapeType::Float_->value:
            case ShapeType::Double->value:
                return match ($str = (string) (float) $value) {
                    'NAN' => 'NaN',
                    'INF' => 'Infinity',
                    '-INF' => '-Infinity',
                    default => (float) $value,
                };

            case ShapeType::Blob->value:
                return \base64_encode($value);

            case ShapeType::BigInteger->value:
                return $this->renderBigInteger($value);

            case ShapeType::BigDecimal->value:
                return $this->renderBigDecimal($value);

            case ShapeType::Timestamp->value:
                if ($context instanceof Schema) {
                    return $this->renderTimestamp($context, $value);
                }

                $resolved = ($this->honorTimestampFormatTrait
                    ? $context
                    : null
                ) ?? $this->settings->defaultTimestampFormat;
                $epoch = $value;
                if ($value instanceof \DateTimeInterface) {
                    $epoch = (float) $value->format('U.u');
                    if ($epoch == (int) $epoch) {
                        $epoch = (int) $epoch;
                    }
                }

                return $resolved === TimestampFormat::EpochSeconds
                    ? $epoch
                    : $resolved->format($epoch);

            case ShapeType::Structure->value:
                return $this->renderStruct($context, $value);

            case ShapeType::Union->value:
                return $this->renderUnion($context, $value);

            case ShapeType::List_->value:
                return $this->renderListInline($context, $value);

            case ShapeType::Map->value:
                return $this->renderMapInline($context, $value);

            case ShapeType::Document->value:
                return $value instanceof DocumentInterface
                    ? $this->renderDocument($value)
                    : $value;

            default:
                return $value;
        }
    }

    private function renderDocument(DocumentInterface $value): mixed
    {
        // Render nested documents without disturbing the outer frame.
        $sub = $this->documentSerializer ??= new self($this->settings);
        $sub->reset();
        $value->serialize($sub, 0);
        if ($sub->rootHasBigNumber) {
            $this->rootHasBigNumber = true;
        }
        $encoded = $sub->root;
        return $encoded;
    }

    private function renderBigInteger(mixed $value): string|BigNumberToken
    {
        $number = $value instanceof BigInteger
            ? $value->value
            : (string) $value;
        $number = self::normalizeBigIntegerDigits($number);

        return $this->useStringForArbitraryPrecision
            ? $number
            : $this->wrapBigNumberToken($number);
    }

    private function renderBigDecimal(mixed $value): string|BigNumberToken
    {
        $number = $value instanceof BigDecimal
            ? $value->value
            : (string) $value;

        return $this->useStringForArbitraryPrecision
            ? $number
            : $this->wrapBigNumberToken($number);
    }

    /**
     * Mark the current payload as containing a big number and create its
     * replacement token.
     */
    private function wrapBigNumberToken(string $number): BigNumberToken
    {
        $this->rootHasBigNumber = true;
        return new BigNumberToken($number);
    }

    /**
     * Normalize a BigInteger digit string to a valid JSON integer token.
     */
    private static function normalizeBigIntegerDigits(string $digits): string
    {
        $negative = $digits !== '' && $digits[0] === '-';
        $unsigned = $negative ? \substr($digits, 1) : $digits;
        $unsigned = \ltrim($unsigned, '0');

        if ($unsigned === '') {
            return '0';
        }

        return $negative ? '-' . $unsigned : $unsigned;
    }
}
