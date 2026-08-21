<?php

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
     */
    public function __construct(
        ?string $serviceNamespace = null,
        ?TimestampFormat $defaultTimestampFormat = null,
        public readonly bool $honorJsonName = true,
        bool $honorTimestampFormatTrait = true
    ) {
        parent::__construct(
            $serviceNamespace,
            $defaultTimestampFormat,
            $honorTimestampFormatTrait
        );
    }
}
