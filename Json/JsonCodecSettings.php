<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codec\Json;

use Smithy\Codec\AbstractCodecSettings;
use Smithy\Core\Serde\TimestampFormat;

/**
 * Configure JSON codec behavior.
 */
final class JsonCodecSettings extends AbstractCodecSettings
{
    /**
     * @param string|null $serviceNamespace Service namespace for codec consumers.
     * @param TimestampFormat|null $defaultTimestampFormat Fallback timestamp format.
     * @param bool $honorJsonName Whether to honor `jsonName`.
     * @param bool $honorTimestampFormatTrait Whether to honor `timestampFormat`.
     * @param bool $useStringForArbitraryPrecision Encode arbitrary-precision values as strings.
     */
    public function __construct(
        ?string $serviceNamespace = null,
        ?TimestampFormat $defaultTimestampFormat = null,
        public readonly bool $honorJsonName = true,
        bool $honorTimestampFormatTrait = true,
        public readonly bool $useStringForArbitraryPrecision = false
    )
    {
        parent::__construct(
            $serviceNamespace,
            $defaultTimestampFormat,
            $honorTimestampFormatTrait
        );
    }
}
