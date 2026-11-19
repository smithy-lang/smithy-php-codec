<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codec\Json;

use Smithy\Core\Shape\Document\AbstractDocument;
use Smithy\Core\Shape\Document\ListDocument;
use Smithy\Core\Shape\Document\MapDocument;

/**
 * Build documents from decoded JSON values.
 *
 * String values become {@see JsonStringDocument}. A `__type` string on a JSON object becomes the
 * map document's discriminator. The shape ID is the text before any `:` URI suffix. Every
 * document built from one value keeps the codec settings it was read with.
 */
final class JsonDocumentReader
{
    /**
     * Build a document from a decoded JSON value.
     *
     * @param mixed $value Decoded JSON value.
     * @param JsonCodecSettings $settings Settings the codec is configured with.
     *
     * @return AbstractDocument|null Document, or null for a JSON null.
     */
    public static function read(
        mixed $value,
        JsonCodecSettings $settings
    ): ?AbstractDocument
    {
        if (\is_string($value)) {
            return new JsonStringDocument($value, $settings);
        }
        if (!\is_array($value)) {
            return AbstractDocument::fromValue($value);
        }

        $children = [];
        foreach ($value as $key => $item) {
            $children[$key] = self::read($item, $settings);
        }
        if (\array_is_list($value)) {
            return new ListDocument($children, null, $settings);
        }

        $type = $value['__type'] ?? null;
        if (\is_string($type)) {
            $type = \strstr($type, ':', true) ?: $type;
        }

        return new MapDocument(
            $children,
            null,
            \is_string($type) && $type !== '' ? $type : null,
            $settings
        );
    }
}
