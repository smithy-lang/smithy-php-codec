<?php

declare(strict_types=1);

namespace Smithy\Codec\FormUrlEncoded;

use Smithy\Core\Serde\AbstractCodec;
use Smithy\Core\Serde\ShapeDeserializerInterface;
use Smithy\Core\Serde\ShapeSerializerInterface;

/**
 * Encode awsQuery-family request bodies as
 * `application/x-www-form-urlencoded`.
 *
 * Responses are XML and are handled by {@see \Smithy\Codec\Xml\XmlCodec},
 * so this codec never produces a deserializer.
 */
final class FormUrlEncodedCodec extends AbstractCodec
{
    /**
     * Idle codec-owned serializers available for internal reuse.
     *
     * @var list<FormUrlEncodedSerializer>
     */
    private array $serializerPool = [];
    private readonly FormUrlEncodedCodecSettings $settings;

    public function __construct(?FormUrlEncodedCodecSettings $settings = null)
    {
        $this->settings = $settings ?? new FormUrlEncodedCodecSettings();
    }

    /**
     * Create a form-urlencoded serializer.
     */
    public function createSerializer(): ShapeSerializerInterface
    {
        return new FormUrlEncodedSerializer($this->settings);
    }

    /**
     * Reject deserializer creation.
     *
     * @throws \LogicException
     */
    public function createDeserializer(string $source): ShapeDeserializerInterface
    {
        throw new \LogicException(
            'FormUrlEncodedCodec does not support deserialization. '
            . 'awsQuery responses are XML. Route them through XmlCodec instead.'
        );
    }

    /**
     * Borrow a reusable serializer for internal callers.
     *
     * @internal
     */
    public function acquireSerializer(): FormUrlEncodedSerializer
    {
        $serializer = \array_pop($this->serializerPool)
            ?? new FormUrlEncodedSerializer($this->settings);
        $serializer->reset();

        return $serializer;
    }

    /**
     * Return an internally borrowed serializer to this codec.
     *
     * @internal
     *
     * @return void
     */
    public function releaseSerializer(
        FormUrlEncodedSerializer $serializer
    ): void
    {
        $serializer->reset();
        $this->serializerPool[] = $serializer;
    }
}
