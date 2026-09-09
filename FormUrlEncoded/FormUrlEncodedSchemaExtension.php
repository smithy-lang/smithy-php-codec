<?php

declare(strict_types=1);

namespace Smithy\Codec\FormUrlEncoded;

use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\TimestampFormat;

/**
 * Store precomputed form-urlencoded metadata on a schema.
 *
 * Used on request serialization only.
 *
 * @internal Attached by {@see FormUrlEncodedSchemaExtensionProvider}.
 */
final class FormUrlEncodedSchemaExtension
{
    public const WIRE_MODE_RESOLVED = 0;
    public const WIRE_MODE_CAPITALIZED_RESOLVED = 1;
    public const WIRE_MODE_RAW = 2;
    public const WIRE_MODE_CAPITALIZED_RAW = 3;

    public function __construct(
        /**
         * Member wire-name tables indexed by serializer naming mode.
         *
         * Modes are resolved names, capitalized resolved names, raw
         * Smithy names, and capitalized raw Smithy names. Each table
         * maps member indexes to encoded wire key segments.
         *
         * @var array<int, array<int, string>>
         */
        public readonly array $memberWireNamesByMode = [],

        /**
         * Sparse `memberIndex => TimestampFormat` map for timestamp
         * members with an explicit `@timestampFormat` trait. Members
         * that don't carry the trait are absent; callers fall back to
         * the codec settings default (see the serializer's timestamp
         * method).
         *
         * @var array<int, TimestampFormat>
         */
        public readonly array $tsFormats = [],

        /**
         * Wire key segment for list items, resolved once per list
         * schema. Follows the awsQuery spec:
         *   - `@xmlName` on the list member wins.
         *   - Otherwise the literal `"member"` is used.
         *
         * Note that the member's `@xmlName` is ignored when the
         * container carries `@xmlFlattened` (see the spec's
         * "Collections" example: `FlattenedListArgWithXmlName` shows
         * `@xmlName("Hi")` on the outer flattened member overriding
         * the inner list member's `@xmlName("item")`). The flattened
         * key path uses the outer member's name / `@xmlName`. That
         * lives in the containing struct's `$memberWireNamesByMode`, so
         * this field is read only when `$flattened` is false.
         */
        public readonly ?string $listMemberName = null,

        /** Whether this list is flattened (`@xmlFlattened`). */
        public readonly bool $flattened = false,

        /**
         * Pre-resolved list member {@see \Smithy\Core\Shape\ShapeType}
         * as its `int` value (mirroring {@see Schema::$type}) for
         * list-typed schemas. Saves a `getListMember()?->type` chain on
         * every list write and keeps the serializer's type-dispatch
         * switch pointed at a literal int.
         */
        public readonly ?int $listMemberType = null,

        /**
         * Pre-resolved list member Schema (for list-typed schemas), or
         * null when the list has no resolvable member schema. Handed
         * directly to the serializer's inner-item write paths so the
         * list serialize call doesn't have to reach back to the outer
         * schema for it.
         */
        public readonly ?Schema $listMemberSchema = null,

        /**
         * Pre-resolved {@see TimestampFormat} for the list's inner
         * member when the member is a timestamp; null otherwise.
         * Populated only when the inner member carries an explicit
         * `@timestampFormat` trait. Lets
         * {@see FormUrlEncodedSerializer::serializeList}'s timestamp
         * case emit each element without a per-list
         * `TimestampFormat::fromTraitString(...)` call.
         */
        public readonly ?TimestampFormat $listMemberTimestampFormat = null,

        /**
         * Precomputed full resolved list-item prefix fragment for a
         * list-typed member schema:
         *   - `{$wireName}.{$listMemberName}.` (default)
         *   - `{$wireName}.` (`@xmlFlattened`)
         *
         * Ready to append to the current outer prefix. Null for
         * schemas that are not member-shaped lists.
         */
        public readonly ?string $resolvedMemberListPrefix = null,

        /**
         * Precomputed full capitalized-resolved list-item prefix
         * fragment for a list-typed member schema: always
         * `{$capitalizedResolvedWireName}.`.
         *
         * Ready to append to the current outer prefix. Null for
         * schemas that are not member-shaped lists.
         */
        public readonly ?string $capitalizedResolvedMemberListPrefix = null,

        /**
         * Wire key segment for a map's key entries, resolved once per
         * map schema:
         *   - `@xmlName` on the map's `key` member wins.
         *   - Otherwise the literal `"key"` is used.
         */
        public readonly ?string $mapKeyName = null,

        /**
         * Precomputed `{$mapKeyName}=` fragment for scalar map-key emits.
         */
        public readonly ?string $mapKeyAssignment = null,

        /**
         * Wire key segment for a map's value entries, resolved once per
         * map schema:
         *   - `@xmlName` on the map's `value` member wins.
         *   - Otherwise the literal `"value"` is used.
         */
        public readonly ?string $mapValueName = null,

        /**
         * Precomputed `{$mapValueName}=` fragment for scalar map-value emits.
         */
        public readonly ?string $mapValueAssignment = null,

        /**
         * Pre-resolved map value Schema, mirroring
         * {@see \Smithy\Codec\Xml\XmlSchemaExtension::$mapValueSchema}.
         */
        public readonly ?Schema $mapValueSchema = null,

        /** Pre-resolved map value shape type. */
        public readonly ?int $mapValueType = null,

        /**
         * Pre-resolved {@see TimestampFormat} for a map's value when
         * the value shape is a timestamp; null otherwise. Populated only
         * when the value member carries an explicit `@timestampFormat`
         * trait. Lets
         * {@see FormUrlEncodedSerializer::serializeMap}'s timestamp
         * arm emit the value without a per-map
         * `TimestampFormat::fromTraitString(...)` call.
         */
        public readonly ?TimestampFormat $mapValueTimestampFormat = null,

        /**
         * Precomputed full awsQuery map-entry prefix fragment for a
         * map-typed member schema:
         *   - `{$wireName}.entry.` (default)
         *   - `{$wireName}.` (`@xmlFlattened`)
         *
         * Ready to append to the current outer prefix. Null for
         * schemas that are not member-shaped maps.
         */
        public readonly ?string $memberMapEntryPrefix = null,

        /**
         * `memberName => memberSchema` lookup for union variants, used
         * by the serializer to resolve the active variant's member
         * schema when serializing a union. Same shape as
         * {@see \Smithy\Codec\Xml\XmlSchemaExtension::$unionMembersByName}.
         *
         * @var array<string, Schema>
         */
        public readonly array $unionMembersByName = [],

        /**
         * `memberName => resolved wire name` for union variants.
         * `@xmlName` on the variant wins; otherwise the Smithy
         * `memberName` is used. Populated at provider time so
         * {@see FormUrlEncodedSerializer::serializeUnion} reads the
         * variant's key segment via a single array offset instead of a
         * `getTrait(T_XML_NAME) ?? memberName` chain on the hot path.
         *
         * @var array<string, string>
         */
        public readonly array $resolvedUnionMemberWireNames = [],

        /**
         * `memberName => capitalized resolved wire name` for union
         * variants.
         *
         * @var array<string, string>
         */
        public readonly array $capitalizedResolvedUnionMemberWireNames = [],

        /**
         * Sparse `memberName => TimestampFormat` map for union variants
         * whose target is a timestamp. Populated only for those variants;
         * absent entries fall through to the codec settings default at
         * read time. Matches the
         * same trait-lift-to-provider pattern as {@see $tsFormats} for
         * struct members.
         *
         * @var array<string, TimestampFormat>
         */
        public readonly array $unionMemberTsFormats = [],

        /**
         * Union encode metadata indexed by schema member index.
         *
         * Each entry is `[memberSchema, wireNamesByMode, timestampFormat]`.
         *
         * @var array<int, array{
         *     0: Schema,
         *     1: array<int, string>,
         *     2: ?TimestampFormat
         * }>
         */
        public readonly array $unionEncodeByMemberIndex = []
    ) {}
}
