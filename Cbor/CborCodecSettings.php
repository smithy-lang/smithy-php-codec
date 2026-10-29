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
     * @param int<1, max> $maxBigIntegerBytes Maximum CBOR bignum byte length.
     *
     * @throws \InvalidArgumentException When the bignum limit is less than one.
     */
    public function __construct(
        ?string $serviceNamespace = null,
        ?TimestampFormat $defaultTimestampFormat = null,
        bool $honorTimestampFormatTrait = false,
        public readonly int $maxBigIntegerBytes =
            CborBigNumber::DEFAULT_MAX_BYTES
    ) {
        if ($maxBigIntegerBytes < 1) {
            throw new \InvalidArgumentException(
                'maxBigIntegerBytes must be at least one.'
            );
        }

        parent::__construct(
            $serviceNamespace,
            $defaultTimestampFormat,
            $honorTimestampFormatTrait
        );
    }
}
