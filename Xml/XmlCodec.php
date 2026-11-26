<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codec\Xml;

use Psr\Http\Message\StreamInterface;
use Smithy\Codec\Exception\CodecException;
use Smithy\Codec\AbstractCodec;
use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\ShapeDeserializerInterface;
use Smithy\Core\Serde\ShapeSerializerInterface;

/**
 * Provide XML serialization and deserialization for Smithy shapes.
 *
 * Owns parser creation and pools inactive {@see \XMLWriter} instances so
 * fresh {@see XmlSerializer} objects can reuse writer allocations without
 * sharing live serializer state across callers.
 */
final class XmlCodec extends AbstractCodec
{
    /**
     * Idle XML writers available for reuse.
     *
     * @var list<\XMLWriter>
     */
    private array $writerPool = [];
    private readonly XmlCodecSettings $settings;
    /** @var \Closure(\XMLWriter): void */
    private readonly \Closure $releaseWriter;

    public function __construct(?XmlCodecSettings $settings = null)
    {
        $this->settings = $settings ?? new XmlCodecSettings();
        $this->releaseWriter = function (\XMLWriter $writer): void {
            $this->writerPool[] = $writer;
        };
    }

    /**
     * Create an XML serializer.
     */
    public function createSerializer(): ShapeSerializerInterface
    {
        $writer = \array_pop($this->writerPool) ?? new \XMLWriter();
        $writer->openMemory();

        return new XmlSerializer(
            $writer,
            $this->settings,
            $this->releaseWriter
        );
    }

    /**
     * Create an XML deserializer.
     *
     * @param StreamInterface $source XML text to parse.
     * @param list<string> $extraWrapperElements Additional wrapper element
     *                                           names to descend into for
     *                                           this call.
     */
    public function createDeserializer(
        StreamInterface $source,
        array $extraWrapperElements = []
    ): ShapeDeserializerInterface
    {
        $useInternalErrors = \libxml_use_internal_errors(true);
        try {
            $xml = new \SimpleXMLElement(
                $source->getContents(),
                LIBXML_NOBLANKS | LIBXML_NONET | LIBXML_COMPACT
            );
        } catch (\Exception $e) {
            throw new CodecException(
                'Failed to parse XML: ' . $e->getMessage(),
                0,
                $e
            );
        } finally {
            \libxml_clear_errors();
            \libxml_use_internal_errors($useInternalErrors);
        }

        // Descend through wrapper elements. The codec-configured list
        // runs first (static per-service wrappers if any), then any
        // per-call extras (per-operation envelopes for awsQuery).
        // `isset($xml->{name})` uses SimpleXML's __isset which walks
        // the children of the current element. A match steps in.
        // a miss leaves the current element unchanged (defensive against
        // servers that omit the outer wrapper).
        foreach ($this->settings->wrapperElements as $wrapper) {
            if (isset($xml->{$wrapper})) {
                $xml = $xml->{$wrapper};
            }
        }
        foreach ($extraWrapperElements as $wrapper) {
            if (isset($xml->{$wrapper})) {
                $xml = $xml->{$wrapper};
            }
        }

        return new XmlDeserializer($xml, $this->settings);
    }

    /**
     * Return the XML extension's precomputed positional member template.
     *
     * @return array<int, mixed> Positional member values.
     */
    public function createMembersBuffer(Schema $schema): array
    {
        static $key = null;
        $key ??= XmlSchemaExtensionProvider::$KEY
            ?? XmlSchemaExtensionProvider::key();

        return $schema->getExtension($key)->membersTemplate
            ?? parent::createMembersBuffer($schema);
    }
}
