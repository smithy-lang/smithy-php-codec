<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codec\Cbor;

use Psr\Http\Message\StreamInterface;
use Smithy\Codec\AbstractCodec;
use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\ShapeDeserializerInterface;
use Smithy\Core\Serde\ShapeSerializerInterface;

/**
 * Create CBOR serializers and deserializers for Smithy shapes.
 */
final class CborCodec extends AbstractCodec
{
    private readonly CborCodecSettings $settings;
    /** @var list<CborSerializer> Reusable internal serializers. */
    private array $serializerPool = [];

    /**
     * @param CborCodecSettings|null $settings Codec behavior overrides.
     */
    public function __construct(?CborCodecSettings $settings = null)
    {
        $this->settings = $settings ?? new CborCodecSettings();
    }

    /**
     * Create a CBOR serializer.
     */
    public function createSerializer(): ShapeSerializerInterface
    {
        return new CborSerializer($this->settings);
    }

    /**
     * Create a CBOR deserializer.
     */
    public function createDeserializer(StreamInterface $source): ShapeDeserializerInterface
    {
        return new CborDeserializer($source, $this->settings);
    }

    /**
     * Return the CBOR extension's precomputed positional member template.
     *
     * @return array<int, mixed> Positional member values.
     */
    public function createMembersBuffer(Schema $schema): array
    {
        static $key = null;
        $key ??= CborSchemaExtensionProvider::$KEY
            ?? CborSchemaExtensionProvider::key();

        return $schema->getExtension($key)?->membersTemplate
            ?? parent::createMembersBuffer($schema);
    }

    /**
     * Borrow a reusable serializer.
     *
     * @internal
     */
    public function acquireSerializer(): CborSerializer
    {
        $serializer = \array_pop($this->serializerPool)
            ?? new CborSerializer($this->settings);
        $serializer->reset();

        return $serializer;
    }

    /**
     * Return a borrowed serializer to the pool.
     *
     * @internal
     *
     * @return void
     */
    public function releaseSerializer(ShapeSerializerInterface $serializer): void
    {
        if (!$serializer instanceof CborSerializer) {
            return;
        }

        $serializer->reset();
        $this->serializerPool[] = $serializer;
    }
}
