<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codec\Json;

/**
 * Wrap a big number for JSON serialization.
 */
final class BigNumberToken implements \JsonSerializable
{
    public const ENCODED_PREFIX = '__SMITHY_BIGNUM__';

    public function __construct(public readonly string $number) {}

    /**
     * Return the sentinel-prefixed JSON value.
     */
    public function jsonSerialize(): string
    {
        return self::ENCODED_PREFIX . $this->number;
    }
}
