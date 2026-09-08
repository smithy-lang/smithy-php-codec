<?php

declare(strict_types=1);

namespace Smithy\Codec;

use Smithy\Core\Serde\TimestampFormat;

/**
 * Shared codec settings.
 *
 * Carries service-level metadata that multiple wire formats may need,
 * along with the codec's fallback timestamp format when no explicit
 * `@timestampFormat` trait is present on the schema path being
 * serialized or deserialized, or when the protocol ignores that trait.
 */
abstract class AbstractCodecSettings
{
    public readonly TimestampFormat $defaultTimestampFormat;

    /**
     * @param ?string $serviceNamespace Service namespace for codec consumers.
     * @param ?TimestampFormat $defaultTimestampFormat Fallback timestamp format.
     * @param bool $honorTimestampFormatTrait Whether to honor the modeled
     *                                        `timestampFormat` trait.
     */
    public function __construct(
        public readonly ?string $serviceNamespace = null,
        ?TimestampFormat $defaultTimestampFormat = null,
        public readonly bool $honorTimestampFormatTrait = true
    ) {
        $this->defaultTimestampFormat = $defaultTimestampFormat
            ?? TimestampFormat::EpochSeconds;
    }
}
