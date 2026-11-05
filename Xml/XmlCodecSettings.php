<?php

/*
 * Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
 * SPDX-License-Identifier: Apache-2.0
 */

declare(strict_types=1);

namespace Smithy\Codec\Xml;

use Smithy\Codec\AbstractCodecSettings;
use Smithy\Core\Serde\TimestampFormat;

/**
 * Configure XML codec behavior.
 */
final class XmlCodecSettings extends AbstractCodecSettings
{
    /**
     * @param string|null $serviceNamespace Service namespace for codec consumers.
     * @param TimestampFormat|null $defaultTimestampFormat Fallback timestamp format.
     * @param list<string> $wrapperElements Top-level wrapper element
     *     names to skip during deserialization.
     * @param string|null $defaultXmlNamespace Default XML namespace URI.
     * @param bool $honorTimestampFormatTrait Whether to honor `timestampFormat`.
     */
    public function __construct(
        ?string $serviceNamespace = null,
        ?TimestampFormat $defaultTimestampFormat = null,
        public readonly array $wrapperElements = [],
        public readonly ?string $defaultXmlNamespace = null,
        bool $honorTimestampFormatTrait = true
    ) {
        parent::__construct(
            $serviceNamespace,
            $defaultTimestampFormat,
            $honorTimestampFormatTrait
        );
    }
}
