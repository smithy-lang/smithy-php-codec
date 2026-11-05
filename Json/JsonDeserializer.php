<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codec\Json;

use Psr\Http\Message\StreamInterface;
use Smithy\Codec\Exception\DeserializationException;
use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\SerializableShapeInterface;
use Smithy\Core\Serde\ShapeDeserializerInterface;
use Smithy\Core\Shape\ShapeType;
use Smithy\Core\Serde\TimestampFormat;
use Smithy\Core\Shape\BigDecimal;
use Smithy\Core\Shape\BigInteger;
use Smithy\Core\Shape\Document\AbstractDocument;
use Smithy\Core\Shape\Document\StructDocument;
use Smithy\Core\Shape\Document\UnionDocument;

/**
 * Deserialize JSON into Smithy shapes.
 */
final class JsonDeserializer implements ShapeDeserializerInterface
{
    private readonly JsonCodecSettings $settings;
    private readonly bool $honorTimestampFormatTrait;
    /**
     * Decoded wire body.
     */
    private mixed $parsed;

    public function __construct(
        StreamInterface $source,
        ?JsonCodecSettings $settings = null
    )
    {
        $this->settings = $settings ?? new JsonCodecSettings();
        $this->honorTimestampFormatTrait =
            $this->settings->honorTimestampFormatTrait;
        $decoded = \json_decode(
            $source->getContents(),
            true,
            512,
            \JSON_BIGINT_AS_STRING
        );
        if ($decoded === null && \json_last_error() !== \JSON_ERROR_NONE) {
            throw new DeserializationException(
                'JSON deserialization failed: ' . \json_last_error_msg()
            );
        }
        $this->parsed = $decoded;
    }

    /**
     * Deserialize the parsed wire body into positional schema members.
     *
     * @param array<int, mixed> $members
     *
     * @return void
     */
    public function deserialize(Schema $schema, array &$members): void
    {
        if ($this->parsed === null) {
            return;
        }
        // @httpPayload unions treat the whole document as the union value.
        if ($schema->type === ShapeType::Union->value) {
            $members[0] = $this->readUnion($schema, $this->parsed);
            return;
        }

        if (!\is_array($this->parsed)) {
            // Only JSON objects can populate a struct.
            return;
        }

        $this->readStruct($schema, $this->parsed, $members);
    }

    /**
     * Walk a JSON object into the positional `$members` array for `$schema`.
     *
     * @param array<string, mixed> $object Decoded JSON object.
     * @param array<int, mixed>    $members Positional output, modified in place.
     */
    private function readStruct(Schema $schema, array $object, array &$members): void
    {
        // Cache the extension key in the hot path.
        static $extKey = null;
        $extKey ??= JsonSchemaExtensionProvider::key();
        /** @var JsonSchemaExtension|null $info */
        $info = $schema->getExtension($extKey);
        if ($info === null) {
            return;
        }

        // Pick the wire-name tables once per struct.
        $ordered = $this->settings->honorJsonName
            ? $info->orderedMembers
            : $info->rawOrderedMembers;
        $memberDecodeByWireName = $this->settings->honorJsonName
            ? $info->memberDecodeByWireName
            : $info->rawMemberDecodeByWireName;
        // Favor in-order keys, then fall back to the hash lookup.
        $orderedCount = $info->orderedMemberCount;
        $hint = 0;
        foreach ($object as $key => $value) {
            // Fast path for in-order keys.
            if ($hint < $orderedCount && $ordered[$hint][0] === $key) {
                $entry = $ordered[$hint][1];
                $hint++;
            } else {
                // Ignore unknown wire keys.
                $entry = $memberDecodeByWireName[$key] ?? null;
                if ($entry === null) {
                    continue;
                }
                // Nudge the hint forward after an out-of-order match.
                $scanLimit = $hint + 4 < $orderedCount
                    ? $hint + 4
                    : $orderedCount;
                for ($i = $hint; $i < $scanLimit; $i++) {
                    if ($ordered[$i][0] === $key) {
                        $hint = $i + 1;
                        break;
                    }
                }
            }

            $idx = $entry[0];
            if ($value === null) {
                $members[$idx] = null;
                continue;
            }

            $members[$idx] = $this->readValue(
                $entry[1],
                $value,
                $entry[2] ?? null
            );
        }
    }
    /**
     * Hydrate a nested struct.
     *
     * Uses the prebuilt member template when available. Resolve the
     * target only for stub schemas.
     */
    private function readNestedStruct(
        Schema $member,
        mixed $value
    ): ?SerializableShapeInterface
    {
        if (!\is_array($value)) {
            return null;
        }
        static $extKey = null;
        $extKey ??= JsonSchemaExtensionProvider::key();

        // Resolve the target only for stub schemas.
        /** @var JsonSchemaExtension|null $info */
        $info = $member->getExtension($extKey);
        if ($info === null || $info->memberCount === 0) {
            $targetSchema = $member->resolveTarget();
            $info = $targetSchema->getExtension($extKey);
        } else {
            $targetSchema = $member;
        }

        // Fall back to a fresh member array if no extension is present.
        if ($info !== null) {
            $members = $info->membersTemplate;
        } else {
            $count = $targetSchema->getMemberCount();
            $members = $count > 0 ? \array_fill(0, $count, null) : [];
        }
        $this->readStruct($targetSchema, $value, $members);
        if ($targetSchema->targetClass !== null) {
            return ($targetSchema->targetClass)::fromMembers($members);
        }

        return StructDocument::fromMembers($targetSchema, $members);
    }



    /**
     * Hydrate a union wire value.
     */
    private function readUnion(
        Schema|JsonSchemaExtension $context,
        mixed $value
    ): ?SerializableShapeInterface
    {
        if (!\is_array($value) || $value === []) {
            return null;
        }

        if ($context instanceof JsonSchemaExtension) {
            return $this->readUnionFromInfo($context, $value);
        }

        static $extKey = null;
        $extKey ??= JsonSchemaExtensionProvider::key();

        /** @var JsonSchemaExtension|null $info */
        $info = $context->getExtension($extKey);
        if ($info !== null && $info->resolvedTargetSchema !== null) {
            return $this->readUnionFromInfo($info, $value);
        }

        $unionSchema = $context->resolveTarget();
        $targetClass = $unionSchema->targetClass;

        foreach ($value as $wireKey => $wireValue) {
            if ($wireKey === '__type' || $wireValue === null) {
                continue;
            }

            return $targetClass !== null
                ? $targetClass::unknown((string) $wireKey, $wireValue)
                : UnionDocument::unknown(
                    $unionSchema,
                    (string) $wireKey,
                    $wireValue
                );
        }

        return null;
    }

    /**
     * Hydrate a union using fully resolved extension metadata.
     *
     * @param array<string, mixed> $value
     */
    private function readUnionFromInfo(
        JsonSchemaExtension $info,
        array $value
    ): ?SerializableShapeInterface
    {
        $unionSchema = $info->resolvedTargetSchema;
        $targetClass = $info->resolvedTargetClass;
        $unionDecodeByWireName = $this->settings->honorJsonName
            ? $info->unionDecodeByWireName
            : $info->rawUnionDecodeByWireName;

        foreach ($value as $wireKey => $wireValue) {
            if ($wireKey === '__type' || $wireValue === null) {
                continue;
            }
            $entry = $unionDecodeByWireName[$wireKey] ?? null;

            if ($entry === null) {
                return $targetClass !== null
                    ? $targetClass::unknown((string) $wireKey, $wireValue)
                    : UnionDocument::unknown(
                        $unionSchema,
                        (string) $wireKey,
                        $wireValue
                    );
            }

            $decoded = $this->readValue(
                $entry[2],
                $wireValue,
                $entry[3] ?? null
            );

            if ($targetClass !== null) {
                return $info->hasIndexedUnionFactory
                    ? $targetClass::createByIndex($entry[0], $decoded)
                    : $targetClass::create($entry[1], $decoded);
            }

            return UnionDocument::create(
                $unionSchema,
                $entry[1],
                $decoded
            );
        }

        return null;
    }

    /**
     * Read one wire value using a ShapeType-directed dispatch.
     */
    private function readValue(int $type, mixed $value, mixed $context = null): mixed
    {
        switch ($type) {
            case ShapeType::String_->value:
                return (string) $value;

            case ShapeType::Enum->value:
                return $context !== null
                    ? $context::fromValue((string) $value)
                    : (string) $value;

            case ShapeType::Boolean->value:
                return (bool) $value;

            case ShapeType::Integer->value:
            case ShapeType::Long->value:
            case ShapeType::Short->value:
            case ShapeType::Byte->value:
                return (int) $value;

            case ShapeType::IntEnum->value:
                $intValue = (int) $value;
                return $context !== null
                    ? $context::fromValue($intValue)
                    : $intValue;

            case ShapeType::Float_->value:
            case ShapeType::Double->value:
                return match ($value) {
                    'NaN' => \NAN,
                    'Infinity' => \INF,
                    '-Infinity' => -\INF,
                    default => (float) $value,
                };

            case ShapeType::BigInteger->value:
                return new BigInteger((string) $value);

            case ShapeType::BigDecimal->value:
                return new BigDecimal((string) $value);

            case ShapeType::Blob->value:
                return \base64_decode((string) $value, true) ?: '';

            case ShapeType::Timestamp->value:
                $fmt = ($this->honorTimestampFormatTrait
                    ? $context
                    : null
                ) ?? $this->settings->defaultTimestampFormat;
                return $fmt === TimestampFormat::EpochSeconds
                    ? ((\is_int($value) || \is_float($value))
                        ? (float) $value
                        : (float) (string) $value)
                    : $fmt->parse((string) $value);

            case ShapeType::Structure->value:
                return $this->readNestedStruct($context, $value);

            case ShapeType::Union->value:
                if ($context instanceof JsonSchemaExtension) {
                    return \is_array($value) && $value !== []
                        ? $this->readUnionFromInfo($context, $value)
                        : null;
                }

                return $this->readUnion($context, $value);

            case ShapeType::List_->value:
                return $this->readList($context, $value);

            case ShapeType::Map->value:
                return $this->readMap($context, $value);

            case ShapeType::Document->value:
                return AbstractDocument::fromValue($value);

            default:
                return $value;
        }
    }


    /**
     * Deserialize a JSON list.
     *
     * Uses extension metadata for the member type and timestamp format.
     */
    private function readList(Schema $member, mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $itemSchema = $member->listMember;
        if ($itemSchema === null) {
            // Synthetic schemas without a member schema pass through.
            return $value;
        }

        static $extKey = null;
        $extKey ??= JsonSchemaExtensionProvider::key();
        /** @var JsonSchemaExtension|null $info */
        $info = $member->getExtension($extKey);
        // Synthetic schemas may not have extension metadata.
        $itemType = $info?->listMemberType ?? $itemSchema->type;
        $unionInfo = $itemType === ShapeType::Union->value
            ? $info?->listMemberUnionInfo
            : null;
        if ($unionInfo !== null) {
            $out = [];
            foreach ($value as $item) {
                $out[] = \is_array($item) && $item !== []
                    ? $this->readUnionFromInfo($unionInfo, $item)
                    : null;
            }

            return $out;
        }

        $context = match ($itemType) {
            ShapeType::Timestamp->value => $info?->listMemberTimestampFormat,
            ShapeType::Enum->value,
            ShapeType::IntEnum->value => $info?->listMemberResolvedTargetClass,
            default => $itemSchema,
        };

        $out = [];
        foreach ($value as $item) {
            $out[] = $item === null
                ? null
                : $this->readValue($itemType, $item, $context);
        }

        return $out;
    }


    private function readMap(Schema $member, mixed $value): array
    {
        // `json_decode(..., true)` always returns arrays for objects.
        if (!\is_array($value)) {
            return [];
        }

        $valueSchema = $member->mapValue;
        if ($valueSchema === null) {
            return $value;
        }

        static $extKey = null;
        $extKey ??= JsonSchemaExtensionProvider::key();
        /** @var JsonSchemaExtension|null $info */
        $info = $member->getExtension($extKey);
        $valueType = $info?->mapValueType ?? $valueSchema->type;
        $unionInfo = $valueType === ShapeType::Union->value
            ? $info?->mapValueUnionInfo
            : null;
        if ($unionInfo !== null) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = \is_array($item) && $item !== []
                    ? $this->readUnionFromInfo($unionInfo, $item)
                    : null;
            }

            return $out;
        }

        $context = match ($valueType) {
            ShapeType::Timestamp->value => $info?->mapValueTimestampFormat,
            ShapeType::Enum->value,
            ShapeType::IntEnum->value => $info?->mapValueResolvedTargetClass,
            default => $valueSchema,
        };

        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = $item === null
                ? null
                : $this->readValue($valueType, $item, $context);
        }

        return $out;
    }
}
