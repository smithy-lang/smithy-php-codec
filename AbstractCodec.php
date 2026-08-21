<?php

declare(strict_types=1);

namespace Smithy\Codec;

use Psr\Http\Message\StreamInterface;
use Smithy\Core\Serde\Schema;
use Smithy\Core\Serde\ShapeDeserializerInterface;
use Smithy\Core\Serde\ShapeSerializerInterface;

/**
 * Factory for a format's `ShapeSerializerInterface` and `ShapeDeserializerInterface` pair (JSON,
 * CBOR, XML, etc.).
 *
 * A codec works with bytes. Protocols use codecs to encode and decode the
 * request or response body.
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
        $memberCount = $schema->getMemberCount();

        return $memberCount ? \array_fill(0, $memberCount, null) : [];
    }

    /**
     * Create a serializer for this codec's format.
     *
     * @return ShapeSerializerInterface Fresh serializer instance ready to receive `serializeXxx`
     *                                  calls.
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
