<?php

declare(strict_types=1);

namespace Smithy\Codec\Json;

use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\TimestampFormat;

/**
 * Store precomputed JSON metadata for a schema.
 */
final class JsonSchemaExtension
{
    public function __construct(
        /** @var array<string, int> Wire names to member indices. */
        public readonly array $memberIndexByWireName,

        /** @var array<int, string> Member indices to wire names. */
        public readonly array $wireNameByMemberIndex,

        /** @var array<string, int> Raw member names to member indices. */
        public readonly array $rawMemberIndexByWireName,

        /** @var array<int, string> Member indices to raw member names. */
        public readonly array $rawWireNameByMemberIndex,

        /**
         * Packed decode metadata by wire name.
         *
         * @var array<string, array{0: int, 1: int, 2?: mixed}>
         */
        public readonly array $memberDecodeByWireName,

        /**
         * Packed decode metadata by raw member name.
         *
         * @var array<string, array{0: int, 1: int, 2?: mixed}>
         */
        public readonly array $rawMemberDecodeByWireName,

        /**
         * Ordered `[wireName, decodeEntry]` pairs for in-order lookup.
         *
         * @var list<array{0: string, 1: array{0: int, 1: int, 2?: mixed}}>
         */
        public readonly array $orderedMembers,

        /**
         * Ordered raw `[memberName, decodeEntry]` pairs.
         *
         * @var list<array{0: string, 1: array{0: int, 1: int, 2?: mixed}}>
         */
        public readonly array $rawOrderedMembers,

        /** Cached count of ordered members. */
        public readonly int $orderedMemberCount,

        /** @var array<int, TimestampFormat> Timestamp formats by member index. */
        public readonly array $timestampFormats,

        /** Whether this schema graph may emit a BigNumberToken. */
        public readonly bool $hasBigNumber,

        /** Cached struct or union member count. */
        public readonly int $memberCount = 0,

        /**
         * Prebuilt null-filled member array.
         *
         * @var array<int, null>
         */
        public readonly array $membersTemplate = [],

        /** Cached list member type. */
        public readonly ?int $listMemberType = null,

        /** Resolved list-member timestamp format. */
        public readonly ?TimestampFormat $listMemberTimestampFormat = null,

        /** @var class-string|null Resolved list-member enum target class. */
        public readonly ?string $listMemberResolvedTargetClass = null,

        /** Precomputed union metadata for union-typed list members. */
        public readonly ?self $listMemberUnionInfo = null,

        /** Cached map value type. */
        public readonly ?int $mapValueType = null,

        /** Resolved map-value timestamp format. */
        public readonly ?TimestampFormat $mapValueTimestampFormat = null,

        /** Whether this list or map schema is sparse. */
        public readonly bool $sparse = false,

        /** Resolved timestamp-member format. */
        public readonly ?TimestampFormat $memberTimestampFormat = null,

        /** @var class-string|null Resolved target class for enum, struct, or union members. */
        public readonly ?string $resolvedTargetClass = null,

        /** @var class-string|null Resolved map-value enum target class. */
        public readonly ?string $mapValueResolvedTargetClass = null,

        /** Precomputed union metadata for union-typed map values. */
        public readonly ?self $mapValueUnionInfo = null,

        /** Canonical target schema for union members. */
        public readonly ?Schema $resolvedTargetSchema = null,

        /**
         * Packed union decode metadata by wire name.
         *
         * @var array<string, array{0: int, 1: string, 2: int, 3?: mixed}>
         */
        public readonly array $unionDecodeByWireName = [],

        /**
         * Packed union decode metadata by raw member name.
         *
         * @var array<string, array{0: int, 1: string, 2: int, 3?: mixed}>
         */
        public readonly array $rawUnionDecodeByWireName = [],

        /**
         * Packed union encode metadata by member name.
         *
         * @var array<string, array{0: string, 1: int, 2?: mixed}>
         */
        public readonly array $unionEncodeByMemberName = [],

        /**
         * Packed union encode metadata by member index.
         *
         * @var array<int, array{0: string, 1: int, 2?: mixed}>
         */
        public readonly array $unionEncodeByMemberIndex = [],

        /**
         * Packed union encode metadata by raw member name.
         *
         * @var array<string, array{0: string, 1: int, 2?: mixed}>
         */
        public readonly array $rawUnionEncodeByMemberName = [],

        /**
         * Raw packed union encode metadata by member index.
         *
         * @var array<int, array{0: string, 1: int, 2?: mixed}>
         */
        public readonly array $rawUnionEncodeByMemberIndex = [],

        /** Whether the generated union supports indexed construction. */
        public readonly bool $hasIndexedUnionFactory = false
    ) {}
}
