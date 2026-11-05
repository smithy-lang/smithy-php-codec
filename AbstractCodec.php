<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codec;

use Psr\Http\Message\StreamInterface;
use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\ShapeDeserializerInterface;
use Smithy\Core\Serde\ShapeSerializerInterface;

/**
 * Create serializers and deserializers for one wire format.
 *
 * Codecs translate between Smithy runtime shapes and body bytes. Protocols
 * remain responsible for HTTP metadata and use codecs only to encode and
 * decode request and response bodies.
 *
 * @internal Implementations are subject to change.
 */
abstract class AbstractCodec
{
    /**
     * Create the dense positional buffer used to deserialize a structure.
     *
     * Codecs with precomputed schema metadata should override this method
     * and return their cached members template.
     *
     * @return array<int, mixed> Positional member values.
     */
    public function createMembersBuffer(Schema $schema): array
    {
        return \array_fill(0, $schema->getMemberCount(), null);
    }

    /**
     * Create a serializer for this codec's format.
     *
     * @return ShapeSerializerInterface Fresh serializer ready to receive
     *                                  serialization calls.
     */
    abstract public function createSerializer(): ShapeSerializerInterface;

    /**
     * Create a deserializer for this codec's format.
     *
     * @return ShapeDeserializerInterface Fresh deserializer bound to `$source`.
     */
    abstract public function createDeserializer(
        StreamInterface $source
    ): ShapeDeserializerInterface;
}
