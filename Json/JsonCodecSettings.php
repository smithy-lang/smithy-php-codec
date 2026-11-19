<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codec\Json;

use Smithy\Codec\AbstractCodecSettings;
use Smithy\Core\Serde\TimestampFormat;
use Smithy\Core\Shape\Document\DocumentSettingsInterface;

/**
 * Configure JSON codec behavior.
 */
final class JsonCodecSettings extends AbstractCodecSettings implements DocumentSettingsInterface
{
    /**
     * @param TimestampFormat|null $defaultTimestampFormat Fallback timestamp format.
     * @param bool $honorJsonName Whether to honor `jsonName`.
     * @param bool $honorTimestampFormatTrait Whether to honor `timestampFormat`.
     * @param bool $useStringForArbitraryPrecision Encode arbitrary-precision values as strings.
     */
    public function __construct(
        ?TimestampFormat $defaultTimestampFormat = null,
        public readonly bool $honorJsonName = true,
        bool $honorTimestampFormatTrait = true,
        public readonly bool $useStringForArbitraryPrecision = false
    )
    {
        parent::__construct(
            $defaultTimestampFormat,
            $honorTimestampFormatTrait
        );
    }

    /**
     * Return true when members are read by their `jsonName` trait.
     */
    public function usesJsonName(): bool
    {
        return $this->honorJsonName;
    }

    /**
     * Return true when a member's `timestampFormat` trait selects the string timestamp format.
     */
    public function usesTimestampFormatTrait(): bool
    {
        return $this->honorTimestampFormatTrait;
    }

    /**
     * Return the string timestamp format used when no trait applies.
     */
    public function getDefaultTimestampFormat(): TimestampFormat
    {
        return $this->defaultTimestampFormat;
    }
}
