<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codec\Xml;

use Psr\Http\Message\StreamInterface;
use Smithy\Codec\Exception\SerializationException;
use Smithy\Core\Serde\Schema;
use Smithy\Core\Shape\ShapeType;
use Smithy\Core\Serde\SerializableStructInterface;
use Smithy\Core\Serde\ShapeSerializerInterface;
use Smithy\Core\Serde\TimestampFormat;
use Smithy\Core\Shape\BigDecimal;
use Smithy\Core\Shape\BigInteger;
use Smithy\Core\Shape\Document\DocumentInterface;
use Smithy\Core\Shape\EnumInterface;
use Smithy\Core\Shape\UnionInterface;

/**
 * Serialize Smithy shapes with a C-extension XML writer.
 *
 * Stashes struct context on entry so scalar write methods can resolve wire
 * names and attributes through local arrays instead of repeated extension
 * lookups.
 *
 * @internal Instantiated only by {@see XmlCodec}. External callers should
 *           depend on {@see ShapeSerializerInterface}. The concrete writer
 *           choice is a private detail of the codec.
 */
final class XmlSerializer implements ShapeSerializerInterface
{
    private const STRING_ESCAPE_MAP = [
        '&' => '&amp;',
        '<' => '&lt;',
        '>' => '&gt;',
        '"' => '&quot;',
        "\r" => '&#xD;',
        "\n" => '&#xA;',
        "\u{0085}" => '&#x85;',
        "\u{2028}" => '&#x2028;',
    ];

    private readonly XmlCodecSettings $settings;
    private readonly bool $honorTimestampFormatTrait;
    /** @var array<int, string> memberIndex -> XML wire name */
    private array $wireNames = [];
    /** @var array<int, int> memberIndex -> XmlSchemaExtension::COMPONENT_* */
    private array $memberComponentTypes = [];
    /** @var array<int, array{0: string, 1: string, 2: string}> */
    private array $nsAttrs = [];
    /** @var array<int, TimestampFormat> memberIndex -> TimestampFormat enum */
    private array $tsFormats = [];
    private bool $writerReleased = false;

    /**
     * @param \XMLWriter $writer Backing XML writer.
     * @param (\Closure(\XMLWriter): void)|null $releaseWriter Return the
     *        writer to the codec's pool once this serializer is done.
     */
    public function __construct(
        private readonly \XMLWriter $writer,
        ?XmlCodecSettings $settings = null,
        private readonly ?\Closure $releaseWriter = null
    ) {
        $this->settings = $settings ?? new XmlCodecSettings();
        $this->honorTimestampFormatTrait =
            $this->settings->honorTimestampFormatTrait;
    }

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
        $str = $value ? 'true' : 'false';
        if ($this->memberComponentTypes[$idx]) {
            $this->writer->writeAttribute($this->wireNames[$idx], $str);
        } else {
            $this->writer->writeElement($this->wireNames[$idx], $str);
        }
    }

    /**
     * Serialize an integer-like member.
     *
     * @return void
     */
    public function serializeInteger(
        Schema $schema,
        int $value,
        int $idx
    ): void
    {
        $str = (string) $value;
        if ($this->memberComponentTypes[$idx]) {
            $this->writer->writeAttribute($this->wireNames[$idx], $str);
        } else {
            $this->writer->writeElement($this->wireNames[$idx], $str);
        }
    }

    /**
     * Serialize a floating-point member.
     *
     * @return void
     */
    public function serializeFloat(Schema $schema, float $value, int $idx): void
    {
        $str = match ($s = (string) $value) {
            'NAN' => 'NaN',
            'INF' => 'Infinity',
            '-INF' => '-Infinity',
            default => $s,
        };

        if ($this->memberComponentTypes[$idx]) {
            $this->writer->writeAttribute($this->wireNames[$idx], $str);
        } else {
            $this->writer->writeElement($this->wireNames[$idx], $str);
        }
    }

    /**
     * Serialize a big integer member.
     *
     * @return void
     */
    public function serializeBigInteger(
        Schema $schema,
        BigInteger $value,
        int $idx
    ): void
    {
        if ($this->memberComponentTypes[$idx]) {
            $this->writer->writeAttribute(
                $this->wireNames[$idx],
                $value->value
            );
        } else {
            $this->writer->writeElement(
                $this->wireNames[$idx],
                $value->value
            );
        }
    }

    /**
     * Serialize a big decimal member.
     *
     * @return void
     */
    public function serializeBigDecimal(
        Schema $schema,
        BigDecimal $value,
        int $idx
    ): void
    {
        if ($this->memberComponentTypes[$idx]) {
            $this->writer->writeAttribute(
                $this->wireNames[$idx],
                $value->value
            );
        } else {
            $this->writer->writeElement(
                $this->wireNames[$idx],
                $value->value
            );
        }
    }

    /**
     * Serialize a string-like member.
     *
     * @return void
     */
    public function serializeString(Schema $schema, string $value, int $idx): void
    {
        // String members are the only scalar path that can carry a member namespace.
        $ct = $this->memberComponentTypes[$idx];
        if ($ct === XmlSchemaExtension::COMPONENT_ELEMENT) {
            $this->writeStringElement($this->wireNames[$idx], $value);
            return;
        }
        if ($ct === XmlSchemaExtension::COMPONENT_ATTRIBUTE) {
            $this->writeStringAttribute($this->wireNames[$idx], $value);
            return;
        }
        $ns = $this->nsAttrs[$idx];
        $this->writer->startElement($this->wireNames[$idx]);
        $this->writer->writeAttribute($ns[0], $ns[1]);
        $this->writeStringText($value);
        $this->writer->endElement();
    }

    /**
     * Serialize a blob member.
     *
     * Streams are read in full with `(string)`, which rewinds seekable streams.
     *
     * @return void
     */
    public function serializeBlob(
        Schema $schema,
        string|StreamInterface $value,
        int $idx
    ): void
    {
        $value = (string) $value;
        $encoded = \base64_encode($value);
        if ($this->memberComponentTypes[$idx]) {
            $this->writer->writeAttribute($this->wireNames[$idx], $encoded);
        } else {
            $this->writer->writeElement($this->wireNames[$idx], $encoded);
        }
    }

    /**
     * Serialize a timestamp member.
     *
     * @return void
     */
    public function serializeTimestamp(Schema $schema, float $value, int $idx): void
    {
        $resolved = ($this->honorTimestampFormatTrait
            ? $this->tsFormats[$idx] ?? null
            : null
        ) ?? $this->settings->defaultTimestampFormat;
        $formatted = $resolved->format($value);

        if ($this->memberComponentTypes[$idx]) {
            $this->writer->writeAttribute($this->wireNames[$idx], $formatted);
        } else {
            $this->writer->writeElement($this->wireNames[$idx], $formatted);
        }
    }

    /**
     * Serialize a list member.
     *
     * @param list<mixed> $list
     *
     * @return void
     */
    public function serializeList(
        Schema $schema,
        array $list,
        int $idx
    ): void
    {
        // Cache the XML extension key once per method.
        static $extKey = null;
        $extKey ??= XmlSchemaExtensionProvider::key();

        /** @var XmlSchemaExtension $info */
        $info = $schema->getExtension($extKey);
        $listMember = $info->listMemberSchema;

        $xmlName = $info->xmlName ?: $schema->memberName;
        $itemName = $info->flattened ? $xmlName : $info->listMemberName;

        if (!$info->flattened) {
            $this->writer->startElement($xmlName);
            $this->applyXmlNamespace($info);
        }

        $memberNs = $info->listMemberNamespace;
        $nsUri = $memberNs['uri'] ?? null;
        $nsPrefix = $memberNs['prefix'] ?? null;
        $memberType = $info->listMemberType
            ?? $listMember->type
            ?? ShapeType::Document->value;

        switch ($memberType) {
            case ShapeType::String_->value:
            case ShapeType::Enum->value:
                $this->writeStringItems($list, $itemName, $nsUri, $nsPrefix);
                break;
            case ShapeType::Integer->value:
            case ShapeType::Long->value:
            case ShapeType::Short->value:
            case ShapeType::Byte->value:
                $this->writeIntItems($list, $itemName, $nsUri, $nsPrefix);
                break;
            case ShapeType::Boolean->value:
                $this->writeBoolItems($list, $itemName, $nsUri, $nsPrefix);
                break;
            case ShapeType::Float_->value:
            case ShapeType::Double->value:
                $this->writeFloatItems($list, $itemName, $nsUri, $nsPrefix);
                break;
            case ShapeType::Timestamp->value:
                $timestampFormat = ($this->honorTimestampFormatTrait
                    ? ($info->listMemberTimestampFormat
                        ?? $this->tsFormats[$idx]
                        ?? null)
                    : null
                ) ?? $this->settings->defaultTimestampFormat;
                $this->writeTimestampItems(
                    $list,
                    $itemName,
                    $timestampFormat,
                    $nsUri,
                    $nsPrefix
                );
                break;
            case ShapeType::Blob->value:
                $this->writeBlobItems($list, $itemName, $nsUri, $nsPrefix);
                break;
            case ShapeType::Structure->value:
                $this->writeStructItems($list, $itemName, $listMember);
                break;
            case ShapeType::List_->value:
                if ($listMember !== null) {
                    $this->writeListItems($list, $itemName, $listMember);
                    break;
                }
                $this->writeStringItems($list, $itemName, $nsUri, $nsPrefix);
                break;
            case ShapeType::Map->value:
                if ($listMember !== null) {
                    $this->writeMapItems($list, $itemName, $listMember);
                    break;
                }
                $this->writeStringItems($list, $itemName, $nsUri, $nsPrefix);
                break;
            default:
                if ($listMember !== null) {
                    $this->writeGenericItems(
                        $list,
                        $itemName,
                        $listMember,
                        $nsUri,
                        $nsPrefix
                    );
                    break;
                }
                $this->writeStringItems($list, $itemName, $nsUri, $nsPrefix);
                break;
        }

        if (!$info->flattened) {
            $this->writer->endElement();
        }
    }

    /**
     * Serialize a map member.
     *
     * @param array<array-key, mixed> $map
     *
     * @return void
     */
    public function serializeMap(Schema $schema, array $map, int $idx): void
    {
        // Cache the XML extension key once per method.
        static $extKey = null;
        $extKey ??= XmlSchemaExtensionProvider::key();

        /** @var XmlSchemaExtension $info */
        $info = $schema->getExtension($extKey);
        $mapKey = $schema->getMapKey();
        $mapValue = $schema->getMapValue();

        $keyName = $info->mapKeyName;
        $valueName = $info->mapValueName;
        $entryName = $info->mapEntryName;

        if (!$info->flattened) {
            $this->writer->startElement($info->xmlName ?: $schema->memberName);
            $this->applyXmlNamespace($info);
        }

        $valueType = $mapValue?->type;
        $keyNs = $mapKey?->getExtension($extKey)?->xmlNamespace;
        $keyNsUri = $keyNs['uri'] ?? null;
        $keyNsPrefix = $keyNs['prefix'] ?? null;
        $valueNs = $mapValue?->getExtension($extKey)?->xmlNamespace;
        $valueNsUri = $valueNs['uri'] ?? null;
        $valueNsPrefix = $valueNs['prefix'] ?? null;

        foreach ($map as $key => $value) {
            $this->writer->startElement($entryName);

            if ($keyNsUri !== null) {
                $this->writer->startElement($keyName);
                $this->writer->writeAttribute(
                    $keyNsPrefix ? "xmlns:{$keyNsPrefix}" : 'xmlns',
                    $keyNsUri
                );
                $this->writeStringText((string) $key);
                $this->writer->endElement();
            } else {
                $this->writeStringElement($keyName, (string) $key);
            }

            $this->writer->startElement($valueName);
            if ($valueNsUri !== null) {
                $this->writer->writeAttribute(
                    $valueNsPrefix ? "xmlns:{$valueNsPrefix}" : 'xmlns',
                    $valueNsUri
                );
            }
            if ($mapValue !== null && $valueType !== null) {
                $this->writeScalarOrNested($mapValue, $value, $valueType);
            } else {
                $this->writeStringText((string) $value);
            }
            $this->writer->endElement();
            $this->writer->endElement();
        }

        if (!$info->flattened) {
            $this->writer->endElement();
        }
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
        // Cache the XML extension key once per method.
        static $extKey = null;
        $extKey ??= XmlSchemaExtensionProvider::key();

        /** @var XmlSchemaExtension $info */
        $info = $schema->getExtension($extKey);

        $this->writer->startElement($info->xmlName);

        $ns = $info->xmlNamespace;
        if ($ns) {
            $prefix = $ns['prefix'] ?? null;
            if ($prefix) {
                $this->writer->writeAttribute("xmlns:{$prefix}", $ns['uri']);
            } else {
                $this->writer->writeAttribute('xmlns', $ns['uri']);
            }
        } elseif ($this->settings->defaultXmlNamespace && $schema->memberName) {
            $this->writer->writeAttribute(
                'xmlns',
                $this->settings->defaultXmlNamespace
            );
        }

        $prevWireNames = $this->wireNames;
        $prevMemberComponentTypes = $this->memberComponentTypes;
        $prevNsAttrs = $this->nsAttrs;
        $prevTsFormats = $this->tsFormats;

        $structSchema = $struct->getSchema();
        /** @var XmlSchemaExtension $structInfo */
        $structInfo = $structSchema->getExtension($extKey);
        $this->wireNames = $structInfo->memberWireNames;
        $this->memberComponentTypes = $structInfo->memberComponentTypes;
        $this->nsAttrs = $structInfo->nsAttrs;
        $this->tsFormats = $structInfo->tsFormats;

        if ($schema === $structSchema || !$schema->getMemberCount()) {
            $struct->serializeMembers($this);
        } else {
            if ($info->memberWireNames !== []) {
                $this->wireNames = $info->memberWireNames;
                $this->memberComponentTypes = $info->memberComponentTypes;
                $this->nsAttrs = $info->nsAttrs;
                $this->tsFormats = $info->tsFormats;
            }

            // Attributes must be written before child elements.
            if ($info->hasAttributes) {
                foreach ($info->attributeMembers as $xmlName => $ms) {
                    $value = $struct->getMemberValue($ms);
                    if ($value === null) {
                        continue;
                    }

                    $this->writeStringAttribute(
                        $xmlName,
                        (string) $value
                    );
                }
            }
            foreach ($info->elementMembers as $ms) {
                $value = $struct->getMemberValue($ms);
                if ($value === null) {
                    continue;
                }

                $this->writeMemberValue($ms, $value);
            }
        }

        $this->writer->endElement();

        $this->wireNames = $prevWireNames;
        $this->memberComponentTypes = $prevMemberComponentTypes;
        $this->nsAttrs = $prevNsAttrs;
        $this->tsFormats = $prevTsFormats;
    }

    /**
     * Begin a structure member.
     *
     * @return void
     */
    public function beginStruct(Schema $schema, int $idx): void
    {
        // Cache the XML extension key once per method.
        static $extKey = null;
        $extKey ??= XmlSchemaExtensionProvider::key();

        /** @var XmlSchemaExtension $info */
        $info = $schema->getExtension($extKey);
        $this->writer->startElement($info->xmlName);

        $ns = $info->xmlNamespace;
        if ($ns) {
            $prefix = $ns['prefix'] ?? null;
            if ($prefix) {
                $this->writer->writeAttribute("xmlns:{$prefix}", $ns['uri']);
            } else {
                $this->writer->writeAttribute('xmlns', $ns['uri']);
            }
        } elseif ($this->settings->defaultXmlNamespace && $schema->memberName) {
            $this->writer->writeAttribute(
                'xmlns',
                $this->settings->defaultXmlNamespace
            );
        }

        $this->wireNames = $info->memberWireNames;
        $this->memberComponentTypes = $info->memberComponentTypes;
        $this->nsAttrs = $info->nsAttrs;
        $this->tsFormats = $info->tsFormats;
    }

    /**
     * End the current structure member.
     *
     * @return void
     */
    public function endStruct(): void
    {
        $this->writer->endElement();
    }

    /**
     * Serialize a union member.
     *
     * @return void
     */
    public function serializeUnion(Schema $schema, mixed $union, int $idx): void
    {
        // Cache the XML extension key once per method.
        static $extKey = null;
        $extKey ??= XmlSchemaExtensionProvider::key();

        /** @var XmlSchemaExtension $info */
        $info = $schema->getExtension($extKey);
        $this->writer->startElement($info->xmlName);

        $value = $union->value();
        $memberIndex = $union->getMemberIndex();

        $iterInfo = $info;
        if ($info->unionMembersByIndex === []
            && $union instanceof UnionInterface
        ) {
            $iterInfo = $union->getSchema()->getExtension($extKey);
        }
        $variantSchema = $memberIndex < 0
            ? null
            : $iterInfo->unionMembersByIndex[$memberIndex] ?? null;
        $tag = null;
        if ($variantSchema === null) {
            $tag = $union->tag();
            $variantSchema = $iterInfo->unionMembersByName[$tag] ?? null;
        }

        if ($variantSchema !== null) {
            $prevWireNames = $this->wireNames;
            $prevMemberComponentTypes = $this->memberComponentTypes;
            $prevNsAttrs = $this->nsAttrs;
            $prevTsFormats = $this->tsFormats;
            $this->wireNames = $iterInfo->memberWireNames;
            $this->memberComponentTypes = $iterInfo->memberComponentTypes;
            $this->nsAttrs = $iterInfo->nsAttrs;
            $this->tsFormats = $iterInfo->tsFormats;

            $this->writeMemberValue($variantSchema, $value);

            $this->wireNames = $prevWireNames;
            $this->memberComponentTypes = $prevMemberComponentTypes;
            $this->nsAttrs = $prevNsAttrs;
            $this->tsFormats = $prevTsFormats;
        } else {
            $this->writeStringElement((string) $tag, (string) $value);
        }

        $this->writer->endElement();
    }

    /**
     * Reject document member serialization.
     *
     * @return void
     *
     * @throws SerializationException When XML serialization reaches a document member.
     */
    public function serializeDocument(
        Schema $schema,
        ?DocumentInterface $value,
        int $idx
    ): void
    {
        // Smithy XML protocols have no wire representation for document members.
        throw new SerializationException(\sprintf(
            'XML protocols do not support document-typed members; '
            . 'member "%s" of shape "%s" cannot be serialized.',
            $schema->memberName ?? '(unnamed)',
            $schema->id
        ));
    }

    public function __destruct()
    {
        $this->releaseWriter();
    }

    /** Flush the XML buffer. */
    public function flush(): string
    {
        return $this->writer->outputMemory();
    }

    private static function escapeText(string $value): string
    {
        if (\preg_match('//u', $value) !== 1) {
            return \htmlspecialchars(
                $value,
                \ENT_XML1 | \ENT_COMPAT,
                'UTF-8'
            );
        }

        return \strtr($value, self::STRING_ESCAPE_MAP);
    }

    private static function escapeAttribute(string $value): string
    {
        $escaped = \strtr($value, self::STRING_ESCAPE_MAP);

        return \preg_replace_callback(
            '/[^\x20-\x7E]/u',
            static function (array $matches): string {
                $codepoint = self::utf8Codepoint($matches[0]);
                if ($codepoint === 9) {
                    return '&#9;';
                }

                return '&#x'
                    . \strtoupper(\dechex($codepoint))
                    . ';';
            },
            $escaped
        ) ?? $escaped;
    }

    private static function utf8Codepoint(string $character): int
    {
        /** @var array<int, int> $bytes */
        $bytes = \unpack('C*', $character);
        $first = $bytes[1];

        return match (\count($bytes)) {
            1 => $first,
            2 => (($first & 0x1F) << 6)
                | ($bytes[2] & 0x3F),
            3 => (($first & 0x0F) << 12)
                | (($bytes[2] & 0x3F) << 6)
                | ($bytes[3] & 0x3F),
            4 => (($first & 0x07) << 18)
                | (($bytes[2] & 0x3F) << 12)
                | (($bytes[3] & 0x3F) << 6)
                | ($bytes[4] & 0x3F),
            default => throw new \LogicException(
                'Invalid UTF-8 character while escaping XML.'
            ),
        };
    }

    private static function containsEndOfLine(string $value): bool
    {
        return \str_contains($value, "\n")
            || \str_contains($value, "\r")
            || \str_contains($value, "\u{0085}")
            || \str_contains($value, "\u{2028}");
    }

    private function writeStringText(string $value): void
    {
        if (!self::containsEndOfLine($value)) {
            $this->writer->text($value);

            return;
        }

        $this->writer->writeRaw(self::escapeText($value));
    }

    private function writeStringElement(string $name, string $value): void
    {
        if (!self::containsEndOfLine($value)) {
            $this->writer->writeElement($name, $value);

            return;
        }

        $this->writer->startElement($name);
        $this->writer->writeRaw(self::escapeText($value));
        $this->writer->endElement();
    }

    private function writeStringAttribute(string $name, string $value): void
    {
        if (!self::containsEndOfLine($value)) {
            $this->writer->writeAttribute($name, $value);

            return;
        }

        $this->writer->startAttribute($name);
        $this->writer->writeRaw(self::escapeAttribute($value));
        $this->writer->endAttribute();
    }

    /**
     * Return the owned writer to the codec pool once the serializer is done.
     *
     * @return void
     */
    private function releaseWriter(): void
    {
        if ($this->writerReleased) {
            return;
        }

        $this->writerReleased = true;
        if ($this->releaseWriter !== null) {
            $this->writer->openMemory();
            ($this->releaseWriter)($this->writer);
        }
    }

    /**
     * Write string-like list items.
     *
     * @param list<mixed> $list
     *
     * @return void
     */
    private function writeStringItems(
        array $list,
        string $itemName,
        ?string $nsUri,
        ?string $nsPrefix
    ): void
    {
        if ($nsUri === null) {
            foreach ($list as $item) {
                $this->writeStringElement($itemName, (string) $item);
            }
        } else {
            $nsAttr = $nsPrefix ? "xmlns:{$nsPrefix}" : 'xmlns';
            foreach ($list as $item) {
                $this->writer->startElement($itemName);
                $this->writer->writeAttribute($nsAttr, $nsUri);
                $this->writeStringText((string) $item);
                $this->writer->endElement();
            }
        }
    }

    /**
     * Write integer-like list items.
     *
     * @param list<mixed> $list
     *
     * @return void
     */
    private function writeIntItems(
        array $list,
        string $itemName,
        ?string $nsUri,
        ?string $nsPrefix
    ): void
    {
        if ($nsUri === null) {
            foreach ($list as $item) {
                $this->writer->writeElement($itemName, (string) $item);
            }
        } else {
            $nsAttr = $nsPrefix ? "xmlns:{$nsPrefix}" : 'xmlns';
            foreach ($list as $item) {
                $this->writer->startElement($itemName);
                $this->writer->writeAttribute($nsAttr, $nsUri);
                $this->writer->text((string) $item);
                $this->writer->endElement();
            }
        }
    }

    /**
     * Write boolean list items.
     *
     * @param list<mixed> $list
     *
     * @return void
     */
    private function writeBoolItems(
        array $list,
        string $itemName,
        ?string $nsUri,
        ?string $nsPrefix
    ): void
    {
        if ($nsUri === null) {
            foreach ($list as $item) {
                $this->writer->writeElement(
                    $itemName,
                    $item ? 'true' : 'false'
                );
            }
        } else {
            $nsAttr = $nsPrefix ? "xmlns:{$nsPrefix}" : 'xmlns';
            foreach ($list as $item) {
                $this->writer->startElement($itemName);
                $this->writer->writeAttribute($nsAttr, $nsUri);
                $this->writer->text($item ? 'true' : 'false');
                $this->writer->endElement();
            }
        }
    }

    /**
     * Write floating-point list items.
     *
     * @param list<mixed> $list
     *
     * @return void
     */
    private function writeFloatItems(
        array $list,
        string $itemName,
        ?string $nsUri,
        ?string $nsPrefix
    ): void
    {
        if ($nsUri === null) {
            foreach ($list as $item) {
                $item = (string) $item;
                $this->writer->writeElement($itemName, match ($item) {
                    'NAN' => 'NaN',
                    'INF' => 'Infinity',
                    '-INF'=> '-Infinity',
                    default => $item,
                });
            }
        } else {
            $nsAttr = $nsPrefix ? "xmlns:{$nsPrefix}" : 'xmlns';
            foreach ($list as $item) {
                $this->writer->startElement($itemName);
                $this->writer->writeAttribute($nsAttr, $nsUri);
                $item = (string) $item;
                $this->writer->text(match ($item) {
                    'NAN' => 'NaN',
                    'INF' => 'Infinity',
                    '-INF'=> '-Infinity',
                    default => $item,
                });
                $this->writer->endElement();
            }
        }
    }

    /**
     * Write timestamp list items.
     *
     * @param list<float|int> $list
     *
     * @return void
     */
    private function writeTimestampItems(
        array $list,
        string $itemName,
        TimestampFormat $format,
        ?string $nsUri,
        ?string $nsPrefix
    ): void
    {
        $gmdateFmt = match ($format) {
            TimestampFormat::DateTime => TimestampFormat::FMT_DATE_TIME,
            TimestampFormat::HttpDate => TimestampFormat::FMT_HTTP_DATE,
            TimestampFormat::EpochSeconds => null,
        };

        if ($nsUri === null) {
            if ($gmdateFmt !== null) {
                foreach ($list as $item) {
                    $this->writer->writeElement(
                        $itemName,
                        \gmdate($gmdateFmt, (int) $item)
                    );
                }
            } else {
                foreach ($list as $item) {
                    $this->writer->writeElement(
                        $itemName,
                        \is_int($item)
                            ? (string) $item
                            : \sprintf('%.6g', $item)
                    );
                }
            }
            return;
        }

        $nsAttr = $nsPrefix ? "xmlns:{$nsPrefix}" : 'xmlns';
        if ($gmdateFmt !== null) {
            foreach ($list as $item) {
                $this->writer->startElement($itemName);
                $this->writer->writeAttribute($nsAttr, $nsUri);
                $this->writer->text(\gmdate($gmdateFmt, (int) $item));
                $this->writer->endElement();
            }
        } else {
            foreach ($list as $item) {
                $this->writer->startElement($itemName);
                $this->writer->writeAttribute($nsAttr, $nsUri);
                $this->writer->text(
                    \is_int($item)
                        ? (string) $item
                        : \sprintf('%.6g', $item)
                );
                $this->writer->endElement();
            }
        }
    }

    /**
     * Write blob list items.
     *
     * @param list<string> $list
     *
     * @return void
     */
    private function writeBlobItems(
        array $list,
        string $itemName,
        ?string $nsUri,
        ?string $nsPrefix
    ): void
    {
        if ($nsUri === null) {
            foreach ($list as $item) {
                $this->writer->writeElement($itemName, \base64_encode($item));
            }
        } else {
            $nsAttr = $nsPrefix ? "xmlns:{$nsPrefix}" : 'xmlns';
            foreach ($list as $item) {
                $this->writer->startElement($itemName);
                $this->writer->writeAttribute($nsAttr, $nsUri);
                $this->writer->text(\base64_encode($item));
                $this->writer->endElement();
            }
        }
    }

    /**
     * Write structure list items.
     *
     * @param list<SerializableStructInterface> $list
     *
     * @return void
     */
    private function writeStructItems(array $list, string $itemName, Schema $listMember): void
    {
        // Cache the XML extension key once per method.
        static $extKey = null;
        $extKey ??= XmlSchemaExtensionProvider::key();

        // Struct items use the list wrapper name, not the struct schema's xmlName.
        if ($list === []) {
            return;
        }

        /** @var XmlSchemaExtension $structInfo */
        $structInfo = $listMember->getExtension($extKey);

        $ns = $structInfo->xmlNamespace;
        $nsAttr = null;
        $nsUri = null;
        if ($ns !== null && isset($ns['uri'])) {
            $nsAttr = isset($ns['prefix']) ? "xmlns:{$ns['prefix']}" : 'xmlns';
            $nsUri = $ns['uri'];
        }

        $prevWireNames = $this->wireNames;
        $prevMemberComponentTypes = $this->memberComponentTypes;
        $prevNsAttrs = $this->nsAttrs;
        $prevTsFormats = $this->tsFormats;

        $actualInfo = null;
        foreach ($list as $item) {
            $this->writer->startElement($itemName);
            if ($nsAttr !== null) {
                $this->writer->writeAttribute($nsAttr, $nsUri);
            }

            if ($actualInfo === null) {
                /** @var XmlSchemaExtension $actualInfo */
                $actualInfo = $item->getSchema()->getExtension($extKey);
                $this->wireNames = $actualInfo->memberWireNames;
                $this->memberComponentTypes = $actualInfo->memberComponentTypes;
                $this->nsAttrs = $actualInfo->nsAttrs;
                $this->tsFormats = $actualInfo->tsFormats;
            }

            $item->serializeMembers($this);
            $this->writer->endElement();
        }

        $this->wireNames = $prevWireNames;
        $this->memberComponentTypes = $prevMemberComponentTypes;
        $this->nsAttrs = $prevNsAttrs;
        $this->tsFormats = $prevTsFormats;
    }

    /**
     * Write nested list items.
     *
     * @param list<list<mixed>> $list
     *
     * @return void
     */
    private function writeListItems(array $list, string $itemName, Schema $listMember): void
    {
        foreach ($list as $item) {
            $this->writer->startElement($itemName);
            $this->writeNestedList($listMember, $item);
            $this->writer->endElement();
        }
    }

    /**
     * Write nested map items.
     *
     * @param list<array<array-key, mixed>> $list
     *
     * @return void
     */
    private function writeMapItems(array $list, string $itemName, Schema $listMember): void
    {
        foreach ($list as $item) {
            $this->writer->startElement($itemName);
            $this->writeNestedMap($listMember, $item);
            $this->writer->endElement();
        }
    }

    /**
     * Write generic list items with per-item dispatch.
     *
     * @param list<mixed> $list
     *
     * @return void
     */
    private function writeGenericItems(
        array $list,
        string $itemName,
        Schema $listMember,
        ?string $nsUri,
        ?string $nsPrefix
    ): void
    {
        foreach ($list as $item) {
            $this->writer->startElement($itemName);
            if ($nsUri !== null) {
                $this->writer->writeAttribute(
                    $nsPrefix ? "xmlns:{$nsPrefix}" : 'xmlns',
                    $nsUri
                );
            }
            $this->writeScalarOrNested($listMember, $item, $listMember->type);
            $this->writer->endElement();
        }
    }
    /**
     * Serialize a member value by shape type.
     *
     * @return void
     */
    private function writeMemberValue(Schema $memberSchema, mixed $value): void
    {
        $idx = $memberSchema->memberIndex;
        switch ($memberSchema->type) {
            case ShapeType::Structure->value:
                $this->serializeStruct($memberSchema, $value, $idx);
                return;
            case ShapeType::List_->value:
                $this->serializeList(
                    $memberSchema,
                    $value,
                    $idx
                );
                return;
            case ShapeType::Map->value:
                $this->serializeMap($memberSchema, $value, $idx);
                return;
            case ShapeType::Boolean->value:
                $this->serializeBoolean($memberSchema, $value, $idx);
                return;
            case ShapeType::Integer->value:
            case ShapeType::Long->value:
            case ShapeType::Short->value:
            case ShapeType::Byte->value:
                $this->serializeInteger($memberSchema, $value, $idx);
                return;
            case ShapeType::Float_->value:
            case ShapeType::Double->value:
                $this->serializeFloat($memberSchema, $value, $idx);
                return;
            case ShapeType::BigInteger->value:
                $this->serializeBigInteger($memberSchema, $value, $idx);
                return;
            case ShapeType::BigDecimal->value:
                $this->serializeBigDecimal($memberSchema, $value, $idx);
                return;
            case ShapeType::Blob->value:
                $this->serializeBlob($memberSchema, $value, $idx);
                return;
            case ShapeType::Timestamp->value:
                $this->serializeTimestamp($memberSchema, $value, $idx);
                return;
            case ShapeType::String_->value:
                $this->serializeString(
                    $memberSchema,
                    $value instanceof EnumInterface
                        ? (string) $value->getValue()
                        : (string) $value,
                    $idx
                );
                return;
            case ShapeType::IntEnum->value:
                $this->serializeInteger(
                    $memberSchema,
                    $value instanceof EnumInterface
                        ? (int) $value->getValue()
                        : (int) $value,
                    $idx
                );
                return;
            case ShapeType::Union->value:
                $this->serializeUnion($memberSchema, $value, $idx);
                return;
            default:
                $this->serializeString($memberSchema, (string) $value, $idx);
                return;
        }
    }

    /**
     * Write a scalar or nested value inline.
     *
     * @return void
     */
    private function writeScalarOrNested(Schema $schema, mixed $value, int $type): void
    {
        switch ($type) {
            case ShapeType::Structure->value:
                $this->writeStructInline($schema, $value);
                return;
            case ShapeType::List_->value:
                $this->writeNestedList($schema, $value);
                return;
            case ShapeType::Map->value:
                $this->writeNestedMap($schema, $value);
                return;
            case ShapeType::Boolean->value:
                $this->writer->text($value ? 'true' : 'false');
                return;
            case ShapeType::Integer->value:
            case ShapeType::Long->value:
            case ShapeType::Short->value:
            case ShapeType::Byte->value:
            case ShapeType::IntEnum->value:
                $this->writer->text(
                    (string) (
                        $value instanceof EnumInterface
                            ? $value->getValue()
                            : $value
                    )
                );
                return;
            case ShapeType::Float_->value:
            case ShapeType::Double->value:
                $this->writer->text((string) $value);
                return;
            case ShapeType::BigInteger->value:
            case ShapeType::BigDecimal->value:
                $this->writer->text($value->value);
                return;
            case ShapeType::Blob->value:
                $this->writer->text(\base64_encode($value));
                return;
            case ShapeType::Timestamp->value:
                $this->writer->text(
                    (($this->honorTimestampFormatTrait
                        ? $this->tsFormats[$schema->memberIndex] ?? null
                        : null
                    ) ?? $this->settings->defaultTimestampFormat)
                        ->format($value)
                );
                return;
            case ShapeType::Enum->value:
                $this->writeStringText(
                    $value instanceof EnumInterface
                        ? (string) $value->getValue()
                        : (string) $value
                );
                return;
            default:
                $this->writeStringText((string) $value);
                return;
        }
    }

    /**
     * Write a nested structure without its wrapper element.
     *
     * @return void
     */
    private function writeStructInline(
        Schema $schema,
        SerializableStructInterface $struct
    ): void
    {
        // Cache the XML extension key once per method.
        static $extKey = null;
        $extKey ??= XmlSchemaExtensionProvider::key();

        /** @var XmlSchemaExtension $structInfo */
        $structInfo = $schema->getExtension($extKey);

        $prevWireNames = $this->wireNames;
        $prevMemberComponentTypes = $this->memberComponentTypes;
        $prevNsAttrs = $this->nsAttrs;
        $prevTsFormats = $this->tsFormats;

        $this->wireNames = $structInfo->memberWireNames;
        $this->memberComponentTypes = $structInfo->memberComponentTypes;
        $this->nsAttrs = $structInfo->nsAttrs;
        $this->tsFormats = $structInfo->tsFormats;

        $struct->serializeMembers($this);

        $this->wireNames = $prevWireNames;
        $this->memberComponentTypes = $prevMemberComponentTypes;
        $this->nsAttrs = $prevNsAttrs;
        $this->tsFormats = $prevTsFormats;
    }

    /**
     * Write a nested list value.
     *
     * @param list<mixed> $list
     *
     * @return void
     */
    private function writeNestedList(Schema $schema, array $list): void
    {
        // Cache the XML extension key once per method.
        static $extKey = null;
        $extKey ??= XmlSchemaExtensionProvider::key();

        /** @var XmlSchemaExtension $info */
        $info = $schema->getExtension($extKey);
        $listMember = $schema->getListMember();
        $memberName = $info->listMemberName ?? 'member';

        foreach ($list as $item) {
            $this->writer->startElement($memberName);
            if ($listMember !== null) {
                $this->writeScalarOrNested(
                    $listMember,
                    $item,
                    $listMember->type
                );
            } else {
                $this->writeStringText((string) $item);
            }
            $this->writer->endElement();
        }
    }

    /**
     * Write a nested map value.
     *
     * @param array<array-key, mixed> $map
     *
     * @return void
     */
    private function writeNestedMap(Schema $schema, array $map): void
    {
        // Cache the XML extension key once per method.
        static $extKey = null;
        $extKey ??= XmlSchemaExtensionProvider::key();

        /** @var XmlSchemaExtension $info */
        $info = $schema->getExtension($extKey);
        $mapValue = $schema->getMapValue();
        $keyName = $info->mapKeyName ?? 'key';
        $valueName = $info->mapValueName ?? 'value';

        foreach ($map as $key => $value) {
            $this->writer->startElement('entry');
            $this->writeStringElement($keyName, (string) $key);
            $this->writer->startElement($valueName);
            if ($mapValue !== null) {
                $this->writeScalarOrNested($mapValue, $value, $mapValue->type);
            } else {
                $this->writeStringText((string) $value);
            }
            $this->writer->endElement();
            $this->writer->endElement();
        }
    }

    /**
     * Write the namespace declaration for an XML element.
     *
     * @return void
     */
    private function applyXmlNamespace(XmlSchemaExtension $info): void
    {
        $ns = $info->xmlNamespace;
        if ($ns === null) {
            return;
        }

        if (isset($ns['uri'])) {
            $prefix = $ns['prefix'] ?? null;
            if ($prefix !== null) {
                $this->writer->writeAttribute("xmlns:{$prefix}", $ns['uri']);
            } else {
                $this->writer->writeAttribute('xmlns', $ns['uri']);
            }
        }
    }

}
