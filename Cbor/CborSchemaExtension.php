<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codec\Cbor;

use Smithy\Core\Serde\{
    Schema,
    TimestampFormat
};

/**
 * Store precomputed CBOR metadata for a schema.
 */
final class CborSchemaExtension
{
    public function __construct(
        /** Encoded CBOR member name, or null for non-members. */
        public readonly ?string $memberNameBytes = null,

        /**
         * Member schemas in ascending memberIndex order.
         *
         * @var list<Schema>
         */
        public readonly array $orderedMemberSchemas = [],

        /**
         * Member indices parallel to orderedMemberSchemas.
         *
         * @var list<int>
         */
        public readonly array $orderedMemberIndexes = [],

        /**
         * Member shape types parallel to orderedMemberSchemas.
         *
         * @var list<int>
         */
        public readonly array $orderedMemberTypes = [],

        /**
         * Raw member-name bytes in ascending memberIndex order.
         *
         * @var list<string>
         */
        public readonly array $orderedMemberNameBytes = [],

        /**
         * Name lengths parallel to orderedMemberNameBytes.
         *
         * @var list<int>
         */
        public readonly array $orderedMemberNameLengths = [],

        /**
         * FNV-1a hashes parallel to orderedMemberNameBytes.
         *
         * @var list<int>
         */
        public readonly array $orderedMemberNameHashes = [],

        /**
         * Packed short-name lookup table for 1-byte names.
         *
         * @var array<int, int>
         */
        public readonly array $orderedMemberLookup1 = [],

        /**
         * Packed short-name lookup table for 2-byte names.
         *
         * @var array<int, int>
         */
        public readonly array $orderedMemberLookup2 = [],

        /**
         * Packed short-name lookup table for 3-byte names.
         *
         * @var array<int, int>
         */
        public readonly array $orderedMemberLookup3 = [],

        /**
         * Packed short-name lookup table for 4-byte names.
         *
         * @var array<int, int>
         */
        public readonly array $orderedMemberLookup4 = [],

        /**
         * Resolved target classes parallel to orderedMemberSchemas.
         *
         * @var list<?string>
         */
        public readonly array $orderedMemberResolvedTargetClasses = [],

        /**
         * Resolved target schemas parallel to orderedMemberSchemas.
         *
         * @var list<?Schema>
         */
        public readonly array $orderedMemberResolvedTargetSchemas = [],

        /**
         * Encoded field names by memberIndex.
         *
         * @var array<int, string>
         */
        public readonly array $fieldNameTable = [],

        /** Cached member count. */
        public readonly int $memberCount = 0,

        /**
         * Null-filled member template.
         *
         * @var array<int, mixed>
         */
        public readonly array $membersTemplate = [],

        /** Cached list-member type. */
        public readonly ?int $listMemberType = null,

        /** Resolved list-member schema. */
        public readonly ?Schema $listMemberSchema = null,

        /** Resolved target class for list members. */
        public readonly ?string $listMemberResolvedTargetClass = null,

        /** Resolved target schema for list members. */
        public readonly ?Schema $listMemberResolvedTargetSchema = null,

        /** Precomputed union metadata for union-typed list members. */
        public readonly ?self $listMemberUnionInfo = null,

        /** Explicit timestamp format for timestamp list members. */
        public readonly ?TimestampFormat $listMemberTimestampFormat = null,

        /** Whether a list or map schema is sparse. */
        public readonly bool $sparse = false,

        /** Resolved map value schema. */
        public readonly ?Schema $mapValueSchema = null,

        /** Cached map-value type. */
        public readonly ?int $mapValueType = null,

        /** Resolved target class for map values. */
        public readonly ?string $mapValueResolvedTargetClass = null,

        /** Resolved target schema for map values. */
        public readonly ?Schema $mapValueResolvedTargetSchema = null,

        /** Precomputed union metadata for union-typed map values. */
        public readonly ?self $mapValueUnionInfo = null,

        /** Explicit timestamp format for timestamp map values. */
        public readonly ?TimestampFormat $mapValueTimestampFormat = null,

        /** Resolved target class for member schemas. */
        public readonly ?string $resolvedTargetClass = null,

        /** Resolved target schema for recursive stub members. */
        public readonly ?Schema $resolvedTargetSchema = null,

        /**
         * Complete union decode entries in member-index order.
         *
         * @var list<array{
         *     0: int,
         *     1: string,
         *     2: int,
         *     3: Schema,
         *     4: ?string,
         *     5: ?Schema
         * }>
         */
        public readonly array $unionDecodeEntries = [],

        /**
         * Complete union encode metadata by member name.
         *
         * @var array<string, array{
         *     0: int,
         *     1: int,
         *     2: Schema,
         *     3: string,
         *     4: ?string,
         *     5: ?Schema
         * }>
         */
        public readonly array $unionEncodeByMemberName = [],

        /**
         * Complete union encode metadata by member index.
         *
         * @var array<int, array{
         *     0: int,
         *     1: int,
         *     2: Schema,
         *     3: string,
         *     4: ?string,
         *     5: ?Schema
         * }>
         */
        public readonly array $unionEncodeByMemberIndex = [],

        /** Whether the generated union supports indexed construction. */
        public readonly bool $hasIndexedUnionFactory = false
    ) {}
}
