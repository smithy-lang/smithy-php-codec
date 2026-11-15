<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codec;

use Smithy\Core\Serde\TimestampFormat;

/**
 * Shared codec settings.
 *
 * Carries the codec's fallback timestamp format when no explicit
 * `@timestampFormat` trait is present on the schema path being
 * serialized or deserialized, or when the protocol ignores that trait.
 */
abstract class AbstractCodecSettings
{
    public readonly TimestampFormat $defaultTimestampFormat;

    /**
     * @param ?TimestampFormat $defaultTimestampFormat Fallback timestamp format.
     * @param bool $honorTimestampFormatTrait Whether to honor the modeled
     *                                        `timestampFormat` trait.
     */
    public function __construct(
        ?TimestampFormat $defaultTimestampFormat = null,
        public readonly bool $honorTimestampFormatTrait = true
    ) {
        $this->defaultTimestampFormat = $defaultTimestampFormat
            ?? TimestampFormat::EpochSeconds;
    }
}
