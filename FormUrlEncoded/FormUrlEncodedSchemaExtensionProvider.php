<?php

declare(strict_types=1);

namespace Smithy\Codec\FormUrlEncoded;

use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\SchemaExtensionKey;
use Smithy\Core\Serde\SchemaExtensionProviderInterface;
use Smithy\Core\Serde\TimestampFormat;
use Smithy\Core\Shape\ShapeType;

/**
 * Provide precomputed form-urlencoded metadata for schemas.
 *
 * Used on request serialization only. Response bodies are XML and go
 * through {@see \Smithy\Codec\Xml\XmlSchemaExtensionProvider}.
 *
 * @internal Wired into {@see FormUrlEncodedCodec}.
 */
final class FormUrlEncodedSchemaExtensionProvider
    implements SchemaExtensionProviderInterface
{
    /**
     * Singleton extension key promoted to a public static field so hot-
     * path callers can bind it to a local `static` once per method-
     * instance and skip the method-call machinery on every subsequent
     * call. Reads that arrive before `key()` has warmed the slot must
     * coalesce through `key()` (see the serializer for the local-static
     * pattern).
     */
    public static ?SchemaExtensionKey $KEY = null;

    /**
     * Return the singleton extension key.
     */
    public static function key(): SchemaExtensionKey
    {
        return self::$KEY ??= new SchemaExtensionKey(new self());
    }

    /**
     * Provide metadata for one schema.
     */
    public function provide(Schema $schema): ?FormUrlEncodedSchemaExtension
    {
        // Structs and unions get the full member walk, wire names,
        // flattened flags, timestamp formats. Same entry point as
        // XmlSchemaExtensionProvider::provide.
        if ($schema->type === ShapeType::Structure->value
            || $schema->type === ShapeType::Union->value
        ) {
            return $this->buildStructInfo($schema);
        }

        // Member schemas targeting lists / maps / scalars.
        if ($schema->isMember()) {
            return $this->buildMemberInfo($schema);
        }

        // Non-member list / map schemas (rare, reached only when a
        // list or map appears as a top-level shape rather than as a
        // struct member). Same fallback shape XmlSchemaExtensionProvider
        // uses.
        if ($schema->type === ShapeType::List_->value) {
            return $this->buildListInfo($schema);
        }
        if ($schema->type === ShapeType::Map->value) {
            return $this->buildMapInfo($schema);
        }

        return null;
    }

    /**
     * Precompute structure or union member metadata.
     */
    private function buildStructInfo(
        Schema $schema
    ): FormUrlEncodedSchemaExtension
    {
        $resolvedMemberWireNames = [];
        $capitalizedResolvedMemberWireNames = [];
        $rawMemberWireNames = [];
        $capitalizedRawMemberWireNames = [];
        $tsFormats = [];
        $unionMembersByName = [];
        $resolvedUnionMemberWireNames = [];
        $capitalizedResolvedUnionMemberWireNames = [];
        $unionMemberTsFormats = [];
        $unionEncodeByMemberIndex = [];
        $isUnion = $schema->type === ShapeType::Union->value;

        foreach ($schema->getMembers() as $memberSchema) {
            $idx = $memberSchema->memberIndex;

            // Query key resolution: `@xmlName` on the member wins,
            // otherwise the Smithy member name. See the spec's "Query
            // key resolution" table.
            $resolvedWireName = self::encodeQuerySegment(
                $memberSchema->getTrait(Schema::T_XML_NAME)
                    ?? $memberSchema->memberName
            );
            $capitalizedResolvedWireName = self::encodeQuerySegment(
                $this->resolveCapitalizedWireName($memberSchema)
            );
            $rawWireName = self::encodeQuerySegment(
                (string) $memberSchema->memberName
            );
            $capitalizedRawWireName = self::encodeQuerySegment(
                \ucfirst((string) $memberSchema->memberName)
            );
            $resolvedMemberWireNames[$idx] = $resolvedWireName;
            $capitalizedResolvedMemberWireNames[$idx]
                = $capitalizedResolvedWireName;
            $rawMemberWireNames[$idx] = $rawWireName;
            $capitalizedRawMemberWireNames[$idx] =
                $capitalizedRawWireName;

            // Timestamp format is sparse. Populate it only when the
            // member carries an explicit `@timestampFormat` trait. The
            // serializer's codec settings default applies when the entry
            // is missing.
            $memberTsFmt = null;
            if ($memberSchema->type === ShapeType::Timestamp->value) {
                $memberTsFmt = TimestampFormat::fromTraitString(
                    $memberSchema->getTrait(Schema::T_TIMESTAMP_FORMAT)
                );
                if ($memberTsFmt !== null) {
                    $tsFormats[$idx] = $memberTsFmt;
                }
            }

            // Union-side parallel maps: schema lookup by name plus the
            // resolved wire name / timestamp format for the variant.
            // The serializer's `serializeUnion` reads these instead of
            // calling `getTrait()` on the variant schema per call.
            // trait lookups have no place on the serde hot path.
            if ($isUnion) {
                $unionMembersByName[$memberSchema->memberName] = $memberSchema;
                $resolvedUnionMemberWireNames[$memberSchema->memberName]
                    = $resolvedWireName;
                $capitalizedResolvedUnionMemberWireNames[
                    $memberSchema->memberName
                ] = $capitalizedResolvedWireName;
                if ($memberTsFmt !== null) {
                    $unionMemberTsFormats[$memberSchema->memberName]
                        = $memberTsFmt;
                }
                $unionEncodeByMemberIndex[$idx] = [
                    $memberSchema,
                    [
                        $resolvedWireName,
                        $capitalizedResolvedWireName,
                        $rawWireName,
                        $capitalizedRawWireName
                    ],
                    $memberTsFmt
                ];
            }
        }

        return new FormUrlEncodedSchemaExtension(
            memberWireNamesByMode: [
                FormUrlEncodedSchemaExtension::WIRE_MODE_RESOLVED
                    => $resolvedMemberWireNames,
                FormUrlEncodedSchemaExtension::WIRE_MODE_CAPITALIZED_RESOLVED
                    => $capitalizedResolvedMemberWireNames,
                FormUrlEncodedSchemaExtension::WIRE_MODE_RAW
                    => $rawMemberWireNames,
                FormUrlEncodedSchemaExtension::WIRE_MODE_CAPITALIZED_RAW
                    => $capitalizedRawMemberWireNames
            ],
            tsFormats: $tsFormats,
            unionMembersByName: $unionMembersByName,
            resolvedUnionMemberWireNames: $resolvedUnionMemberWireNames,
            capitalizedResolvedUnionMemberWireNames:
                $capitalizedResolvedUnionMemberWireNames,
            unionMemberTsFormats: $unionMemberTsFormats,
            unionEncodeByMemberIndex: $unionEncodeByMemberIndex
        );
    }

    /**
     * Resolve the capitalized wire name for a member.
     */
    private function resolveCapitalizedWireName(Schema $schema): string
    {
        $preferredWireName = $schema->getTrait(
            Schema::T_EC2_QUERY_NAME
        );
        if (\is_string($preferredWireName) && $preferredWireName !== '') {
            return $preferredWireName;
        }

        $xmlName = $schema->getTrait(Schema::T_XML_NAME);
        if (\is_string($xmlName) && $xmlName !== '') {
            return \ucfirst($xmlName);
        }

        return \ucfirst((string) $schema->memberName);
    }

    /**
     * Precompute metadata for a list or map member schema.
     */
    private function buildMemberInfo(
        Schema $schema
    ): ?FormUrlEncodedSchemaExtension
    {
        if ($schema->type !== ShapeType::List_->value
            && $schema->type !== ShapeType::Map->value
        ) {
            return null;
        }

        $resolvedMemberWireName = self::encodeQuerySegment(
            $schema->getTrait(Schema::T_XML_NAME) ?? $schema->memberName
        );
        $capitalizedResolvedMemberWireName = self::encodeQuerySegment(
            $this->resolveCapitalizedWireName($schema)
        );

        // List-typed member: precompute item name + flattened flag +
        // inner member type / schema so the serializer's list path
        // reads them all off the extension without a per-write
        // `getListMember()` chain.
        if ($schema->type === ShapeType::List_->value) {
            $listMember = $schema->getListMember();
            $flattened = $schema->hasTrait(Schema::T_XML_FLATTENED);
            // Only the non-flattened case ever emits an item wrapper.
            // For flattened lists the outer struct's wire-name table
            // carries the key; the inner member's `@xmlName` is
            // ignored (see the spec's "Collections" example with
            // `@xmlName("Hi")` on the outer flattened member).
            $listMemberName = $flattened
                ? null
                : self::encodeQuerySegment(
                    $listMember?->getTrait(Schema::T_XML_NAME) ?? 'member'
                );

            // Lists of timestamps: resolve the explicit trait once at
            // build time so the serializer's timestamp arm emits
            // per-element without a per-list trait lookup.
            $listTsFmt = null;
            if ($listMember !== null
                && $listMember->type === ShapeType::Timestamp->value
            ) {
                $listTsFmt = TimestampFormat::fromTraitString(
                    $listMember->getTrait(Schema::T_TIMESTAMP_FORMAT)
                );
            }

            return new FormUrlEncodedSchemaExtension(
                listMemberName: $listMemberName,
                flattened: $flattened,
                listMemberType: $listMember?->type,
                listMemberSchema: $listMember,
                listMemberTimestampFormat: $listTsFmt,
                resolvedMemberListPrefix: $flattened
                    ? $resolvedMemberWireName . '.'
                    : $resolvedMemberWireName . '.' . $listMemberName . '.',
                capitalizedResolvedMemberListPrefix:
                    $capitalizedResolvedMemberWireName . '.'
            );
        }

        // Map-typed member: precompute key / value / entry names and
        // both member schemas. Flattened maps skip the entry wrapper
        // (`.entry.N.key` becomes just `.N.key` under the outer
        // member's key path).
        if ($schema->type === ShapeType::Map->value) {
            $mapKey = $schema->getMapKey();
            $mapValue = $schema->getMapValue();
            $flattened = $schema->hasTrait(Schema::T_XML_FLATTENED);
            $keyName = $mapKey?->getTrait(Schema::T_XML_NAME) ?? 'key';
            $valueName = $mapValue?->getTrait(Schema::T_XML_NAME) ?? 'value';
            $keyName = self::encodeQuerySegment($keyName);
            $valueName = self::encodeQuerySegment($valueName);

            // Map-of-timestamp values: same trait-lift-to-provider
            // pattern as list timestamps above.
            $mapValueTsFmt = null;
            if ($mapValue !== null
                && $mapValue->type === ShapeType::Timestamp->value
            ) {
                $mapValueTsFmt = TimestampFormat::fromTraitString(
                    $mapValue->getTrait(Schema::T_TIMESTAMP_FORMAT)
                );
            }

            return new FormUrlEncodedSchemaExtension(
                flattened: $flattened,
                mapKeyName: $keyName,
                mapKeyAssignment: $keyName . '=',
                mapValueName: $valueName,
                mapValueAssignment: $valueName . '=',
                mapValueSchema: $mapValue,
                mapValueType: $mapValue?->type,
                mapValueTimestampFormat: $mapValueTsFmt,
                memberMapEntryPrefix: $flattened
                    ? $resolvedMemberWireName . '.'
                    : $resolvedMemberWireName . '.entry.'
            );
        }
        return null;
    }

    /**
     * Precompute metadata for a root list schema.
     */
    private function buildListInfo(
        Schema $schema
    ): FormUrlEncodedSchemaExtension
    {
        $listMember = $schema->getListMember();
        $flattened = $schema->hasTrait(Schema::T_XML_FLATTENED);
        $listMemberName = $flattened
            ? null
            : self::encodeQuerySegment(
                $listMember?->getTrait(Schema::T_XML_NAME) ?? 'member'
            );
        $listTsFmt = null;
        if ($listMember !== null
            && $listMember->type === ShapeType::Timestamp->value
        ) {
            $listTsFmt = TimestampFormat::fromTraitString(
                $listMember->getTrait(Schema::T_TIMESTAMP_FORMAT)
            );
        }

        return new FormUrlEncodedSchemaExtension(
            listMemberName: $listMemberName,
            flattened: $flattened,
            listMemberType: $listMember?->type,
            listMemberSchema: $listMember,
            listMemberTimestampFormat: $listTsFmt
        );
    }

    /**
     * Precompute metadata for a root map schema.
     */
    private function buildMapInfo(Schema $schema): FormUrlEncodedSchemaExtension
    {
        $mapKey = $schema->getMapKey();
        $mapValue = $schema->getMapValue();
        $flattened = $schema->hasTrait(Schema::T_XML_FLATTENED);
        $keyName = self::encodeQuerySegment(
            $mapKey?->getTrait(Schema::T_XML_NAME) ?? 'key'
        );
        $valueName = self::encodeQuerySegment(
            $mapValue?->getTrait(Schema::T_XML_NAME) ?? 'value'
        );
        $mapValueTsFmt = null;
        if ($mapValue !== null
            && $mapValue->type === ShapeType::Timestamp->value
        ) {
            $mapValueTsFmt = TimestampFormat::fromTraitString(
                $mapValue->getTrait(Schema::T_TIMESTAMP_FORMAT)
            );
        }

        return new FormUrlEncodedSchemaExtension(
            flattened: $flattened,
            mapKeyName: $keyName,
            mapKeyAssignment: $keyName . '=',
            mapValueName: $valueName,
            mapValueAssignment: $valueName . '=',
            mapValueSchema: $mapValue,
            mapValueType: $mapValue?->type,
            mapValueTimestampFormat: $mapValueTsFmt
        );
    }

    /**
     * Encode one query key segment without encoding separators.
     */
    private static function encodeQuerySegment(string $segment): string
    {
        return \rawurlencode($segment);
    }
}
