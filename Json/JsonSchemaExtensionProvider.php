<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codec\Json;

use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\SchemaExtensionKey;
use Smithy\Core\Serde\SchemaExtensionProviderInterface;
use Smithy\Core\Shape\ShapeType;
use Smithy\Core\Serde\TimestampFormat;

/**
 * Provide precomputed JSON metadata for schemas.
 */
final class JsonSchemaExtensionProvider
    implements SchemaExtensionProviderInterface
{
    /**
     * Shared extension key cache.
     */
    public static ?SchemaExtensionKey $KEY = null;

    /**
     * Return the shared extension key.
     */
    public static function key(): SchemaExtensionKey
    {
        return self::$KEY ??= new SchemaExtensionKey(new self());
    }

    /**
     * Build JSON extension data for a schema.
     */
    public function provide(Schema $schema): ?JsonSchemaExtension
    {
        if ($schema->type === ShapeType::List_->value) {
            return $this->buildListInfo($schema);
        }

        if ($schema->type === ShapeType::Map->value) {
            return $this->buildMapInfo($schema);
        }

        if ($schema->type === ShapeType::Timestamp->value) {
            return $this->buildTimestampInfo($schema);
        }

        if ($schema->type === ShapeType::Enum->value
            || $schema->type === ShapeType::IntEnum->value
        ) {
            return $this->buildEnumMemberInfo($schema);
        }

        if ($schema->type !== ShapeType::Structure->value
            && $schema->type !== ShapeType::Union->value
        ) {
            return null;
        }

        $byIndex = [];
        $memberDecodeByWireName = [];
        $unionDecodeByWireName = [];
        $unionEncodeByMemberName = [];
        $unionEncodeByMemberIndex = [];
        $orderedMembers = [];
        $rawByIndex = [];
        $rawMemberDecodeByWireName = [];
        $rawUnionDecodeByWireName = [];
        $rawUnionEncodeByMemberName = [];
        $rawUnionEncodeByMemberIndex = [];
        $rawOrderedMembers = [];
        $timestampFormats = [];

        $metadataSchema = $schema->type === ShapeType::Union->value
            ? $schema->resolveTarget()
            : $schema;

        foreach ($metadataSchema->getMembers() as $member) {
            $idx = $member->memberIndex;
            $memberName = $member->memberName;
            $wireName = $member->getTrait(Schema::T_JSON_NAME) ?? $memberName;

            $byIndex[$idx] = $wireName;

            // Keep raw lookup tables alongside the @jsonName tables.
            $rawByIndex[$idx] = $memberName;

            if ($member->type === 17 || $member->type === 18) {
                $targetClass = $member->targetClass;
                if ($targetClass === null && $member->getMemberCount() === 0) {
                    $targetClass = $member->resolveTarget()->targetClass;
                }
                $decodeEntry = [$idx, $member->type, $targetClass];
            } elseif ($member->type === 12) {
                $resolved = TimestampFormat::fromTraitString(
                    $member->getTrait(Schema::T_TIMESTAMP_FORMAT)
                );
                if ($resolved !== null) {
                    $timestampFormats[$idx] = $resolved;
                }
                $decodeEntry = [$idx, 12, $resolved];
            } elseif ($member->type === 13
                || $member->type === 14
                || $member->type === 15
                || $member->type === 16
            ) {
                $decodeEntry = [$idx, $member->type, $member];
            } else {
                $decodeEntry = [$idx, $member->type];
            }

            $memberDecodeByWireName[$wireName] = $decodeEntry;
            $unionDecodeByWireName[$wireName] =
                \count($decodeEntry) === 3
                    ? [
                        $idx,
                        $memberName,
                        $decodeEntry[1],
                        $decodeEntry[2]
                    ]
                    : [$idx, $memberName, $decodeEntry[1]];
            $unionEncodeByMemberName[$memberName] =
                \count($decodeEntry) === 3
                    ? [$wireName, $decodeEntry[1], $decodeEntry[2]]
                    : [$wireName, $decodeEntry[1]];
            $unionEncodeByMemberIndex[$idx] =
                $unionEncodeByMemberName[$memberName];
            // Preserve declaration order for the in-order fast path.
            $orderedMembers[] = [$wireName, $decodeEntry];
            $rawMemberDecodeByWireName[$memberName] = $decodeEntry;
            $rawUnionDecodeByWireName[$memberName] =
                \count($decodeEntry) === 3
                    ? [
                        $idx,
                        $memberName,
                        $decodeEntry[1],
                        $decodeEntry[2]
                    ]
                    : [$idx, $memberName, $decodeEntry[1]];
            $rawUnionEncodeByMemberName[$memberName] =
                \count($decodeEntry) === 3
                    ? [$memberName, $decodeEntry[1], $decodeEntry[2]]
                    : [$memberName, $decodeEntry[1]];
            $rawUnionEncodeByMemberIndex[$idx] =
                $rawUnionEncodeByMemberName[$memberName];
            $rawOrderedMembers[] = [$memberName, $decodeEntry];
        }

        // Scan once for reachable big-number or document shapes.
        $visiting = [$schema->id => true];
        $hasBigNumber = self::scanForBigNumber($schema, $visiting);

        // Cache the member template for nested struct reads.
        $memberCount = \count($byIndex);
        $membersTemplate = $memberCount > 0
            ? \array_fill(0, $memberCount, null)
            : [];

        // Cache the canonical target schema for struct and union stubs.
        // Resolve the target extension lazily to avoid recursion here.
        if ($schema->getMemberCount() === 0 && $schema->hasTarget()) {
            $resolvedTargetSchema = $metadataSchema;
            $resolvedTargetClass = $resolvedTargetSchema->targetClass;
        } else {
            $resolvedTargetSchema = $schema;
            $resolvedTargetClass = $schema->targetClass;
        }

        return new JsonSchemaExtension(
            wireNameByMemberIndex: $byIndex,
            rawWireNameByMemberIndex: $rawByIndex,
            memberDecodeByWireName: $memberDecodeByWireName,
            rawMemberDecodeByWireName: $rawMemberDecodeByWireName,
            orderedMembers: $orderedMembers,
            rawOrderedMembers: $rawOrderedMembers,
            orderedMemberCount: \count($orderedMembers),
            timestampFormats: $timestampFormats,
            hasBigNumber: $hasBigNumber,
            memberCount: $memberCount,
            membersTemplate: $membersTemplate,
            resolvedTargetClass: $resolvedTargetClass,
            resolvedTargetSchema: $resolvedTargetSchema,
            unionDecodeByWireName: $unionDecodeByWireName,
            rawUnionDecodeByWireName: $rawUnionDecodeByWireName,
            unionEncodeByMemberName: $unionEncodeByMemberName,
            unionEncodeByMemberIndex: $unionEncodeByMemberIndex,
            rawUnionEncodeByMemberName: $rawUnionEncodeByMemberName,
            rawUnionEncodeByMemberIndex: $rawUnionEncodeByMemberIndex,
            hasIndexedUnionFactory: $resolvedTargetClass !== null
                && \method_exists($resolvedTargetClass, 'createByIndex')
        );
    }

    /**
     * Build extension data for a list member.
     */
    private function buildListInfo(Schema $schema): JsonSchemaExtension
    {
        $inner = $schema->listMember;
        $innerType = $inner?->type;

        $listTsFmt = null;
        if ($inner !== null && $innerType === ShapeType::Timestamp->value) {
            $listTsFmt = TimestampFormat::fromTraitString(
                $inner->getTrait(Schema::T_TIMESTAMP_FORMAT)
            );
        }

        $listMemberResolvedTargetClass = null;
        $listMemberUnionInfo = null;
        if ($inner !== null
            && (
                $innerType === ShapeType::Enum->value
                || $innerType === ShapeType::IntEnum->value
            )
        ) {
            $listMemberResolvedTargetClass = $inner->targetClass;
            if ($listMemberResolvedTargetClass === null
                && $inner->getMemberCount() === 0
            ) {
                $listMemberResolvedTargetClass =
                    $inner->resolveTarget()?->targetClass;
            }
        }
        if ($inner !== null && $innerType === ShapeType::Union->value) {
            $listMemberUnionInfo = $inner->getExtension(self::key());
        }

        return new JsonSchemaExtension(
            wireNameByMemberIndex: [],
            rawWireNameByMemberIndex: [],
            memberDecodeByWireName: [],
            rawMemberDecodeByWireName: [],
            orderedMembers: [],
            rawOrderedMembers: [],
            orderedMemberCount: 0,
            timestampFormats: [],
            hasBigNumber: false,
            listMemberType: $innerType,
            listMemberTimestampFormat: $listTsFmt,
            listMemberResolvedTargetClass: $listMemberResolvedTargetClass,
            listMemberUnionInfo: $listMemberUnionInfo,
            sparse: $schema->hasTrait(Schema::T_SPARSE)
        );
    }

    /**
     * Build extension data for a map member.
     */
    private function buildMapInfo(Schema $schema): JsonSchemaExtension
    {
        $value = $schema->mapValue;
        $valueType = $value?->type;

        $mapTsFmt = null;
        if ($value !== null && $valueType === ShapeType::Timestamp->value) {
            $mapTsFmt = TimestampFormat::fromTraitString(
                $value->getTrait(Schema::T_TIMESTAMP_FORMAT)
            );
        }

        $mapValueResolvedTargetClass = null;
        $mapValueUnionInfo = null;
        if ($value !== null
            && (
                $valueType === ShapeType::Enum->value
                || $valueType === ShapeType::IntEnum->value
            )
        ) {
            $mapValueResolvedTargetClass = $value->targetClass;
            if ($mapValueResolvedTargetClass === null
                && $value->getMemberCount() === 0
            ) {
                $mapValueResolvedTargetClass =
                    $value->resolveTarget()?->targetClass;
            }
        }
        if ($value !== null && $valueType === ShapeType::Union->value) {
            $mapValueUnionInfo = $value->getExtension(self::key());
        }

        return new JsonSchemaExtension(
            wireNameByMemberIndex: [],
            rawWireNameByMemberIndex: [],
            memberDecodeByWireName: [],
            rawMemberDecodeByWireName: [],
            orderedMembers: [],
            rawOrderedMembers: [],
            orderedMemberCount: 0,
            timestampFormats: [],
            hasBigNumber: false,
            mapValueType: $valueType,
            mapValueTimestampFormat: $mapTsFmt,
            sparse: $schema->hasTrait(Schema::T_SPARSE),
            mapValueResolvedTargetClass: $mapValueResolvedTargetClass,
            mapValueUnionInfo: $mapValueUnionInfo
        );
    }

    /**
     * Build extension data for a timestamp member.
     */
    private function buildTimestampInfo(Schema $schema): JsonSchemaExtension
    {
        $fmt = TimestampFormat::fromTraitString(
            $schema->getTrait(Schema::T_TIMESTAMP_FORMAT)
        );

        return new JsonSchemaExtension(
            wireNameByMemberIndex: [],
            rawWireNameByMemberIndex: [],
            memberDecodeByWireName: [],
            rawMemberDecodeByWireName: [],
            orderedMembers: [],
            rawOrderedMembers: [],
            orderedMemberCount: 0,
            timestampFormats: [],
            hasBigNumber: false,
            memberTimestampFormat: $fmt
        );
    }

    /**
     * Build extension data for an enum member.
     */
    private function buildEnumMemberInfo(Schema $schema): JsonSchemaExtension
    {
        return new JsonSchemaExtension(
            wireNameByMemberIndex: [],
            rawWireNameByMemberIndex: [],
            memberDecodeByWireName: [],
            rawMemberDecodeByWireName: [],
            orderedMembers: [],
            rawOrderedMembers: [],
            orderedMemberCount: 0,
            timestampFormats: [],
            hasBigNumber: false,
            resolvedTargetClass: $schema->targetClass
                ?? $schema->resolveTarget()?->targetClass
        );
    }

    /**
     * Scan a schema graph for big-number support.
     *
     * @param array<string, true> $visiting Shape ids on the active walk.
     */
    private static function scanForBigNumber(Schema $schema, array &$visiting): bool
    {
        $type = $schema->type;
        if ($type === ShapeType::BigInteger->value
            || $type === ShapeType::BigDecimal->value
        ) {
            return true;
        }

        // Documents may serialize big numbers at runtime.
        if ($type === ShapeType::Document->value) {
            return true;
        }

        // Resolve recursive stubs once and stop on cycles.
        if ($schema->hasTarget()) {
            $resolved = $schema->resolveTarget();
            if (isset($visiting[$resolved->id])) {
                return false;
            }
            $visiting[$resolved->id] = true;
            $found = self::scanForBigNumber($resolved, $visiting);
            unset($visiting[$resolved->id]);
            return $found;
        }

        if ($type === ShapeType::Structure->value
            || $type === ShapeType::Union->value
        ) {
            foreach ($schema->getMembers() as $member) {
                if (self::scanForBigNumber($member, $visiting)) {
                    return true;
                }
            }
            return false;
        }

        if ($type === ShapeType::List_->value) {
            return $schema->listMember !== null
                && self::scanForBigNumber($schema->listMember, $visiting);
        }

        if ($type === ShapeType::Map->value) {
            return $schema->mapValue !== null
                && self::scanForBigNumber($schema->mapValue, $visiting);
        }

        return false;
    }
}
