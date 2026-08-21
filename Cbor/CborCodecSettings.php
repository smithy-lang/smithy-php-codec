<?php

declare(strict_types=1);

namespace Smithy\Codec\Cbor;

use Smithy\Codec\AbstractCodecSettings;
use Smithy\Core\Serde\TimestampFormat;

/**
 * Configure CBOR codec behavior.
 */
final class CborCodecSettings extends AbstractCodecSettings
{
    /**
     * @param string|null $serviceNamespace Service namespace for codec consumers.
     * @param TimestampFormat|null $defaultTimestampFormat Fallback timestamp format.
     * @param bool $honorTimestampFormatTrait Whether to honor `timestampFormat`.
     */
    public function __construct(
        ?string $serviceNamespace = null,
        ?TimestampFormat $defaultTimestampFormat = null,
        bool $honorTimestampFormatTrait = false
    ) {
        parent::__construct(
            $serviceNamespace,
            $defaultTimestampFormat,
            $honorTimestampFormatTrait
        );
    }
}
