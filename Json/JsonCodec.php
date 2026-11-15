<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codec\Json;

use Psr\Http\Message\StreamInterface;
use Smithy\Codec\AbstractCodec;
use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\ShapeDeserializerInterface;
use Smithy\Core\Serde\ShapeSerializerInterface;

/**
 * Provide JSON serialization and deserialization for Smithy shapes.
 */
final class JsonCodec extends AbstractCodec
{
    /** @var list<JsonSerializer> Reusable internal serializers. */
    private array $serializerPool = [];
    private readonly JsonCodecSettings $settings;

    public function __construct(?JsonCodecSettings $settings = null)
    {
        $this->settings = $settings ?? new JsonCodecSettings();
    }

    /**
     * Create a JSON serializer.
     */
    public function createSerializer(): ShapeSerializerInterface
    {
        return new JsonSerializer($this->settings);
    }

    /**
     * Create a JSON deserializer.
     */
    public function createDeserializer(StreamInterface $source): ShapeDeserializerInterface
    {
        return new JsonDeserializer($source, $this->settings);
    }

    /**
     * Return the JSON extension's precomputed positional member template.
     *
     * @return array<int, mixed> Positional member values.
     */
    public function createMembersBuffer(Schema $schema): array
    {
        static $key = null;
        $key ??= JsonSchemaExtensionProvider::$KEY
            ?? JsonSchemaExtensionProvider::key();

        return $schema->getExtension($key)?->membersTemplate
            ?? parent::createMembersBuffer($schema);
    }

    /**
     * Borrow a reusable serializer.
     *
     * @internal
     */
    public function acquireSerializer(): JsonSerializer
    {
        $serializer = \array_pop($this->serializerPool)
            ?? new JsonSerializer($this->settings);
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
        if (!$serializer instanceof JsonSerializer) {
            return;
        }

        $serializer->reset();
        $this->serializerPool[] = $serializer;
    }
}
