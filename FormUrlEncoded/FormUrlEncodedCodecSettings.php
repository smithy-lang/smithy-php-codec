<?php

declare(strict_types=1);

namespace Smithy\Codec\FormUrlEncoded;

use Smithy\Codec\AbstractCodecSettings;
use Smithy\Core\Serde\TimestampFormat;

/**
 * Configure form-urlencoded codec behavior.
 */
final class FormUrlEncodedCodecSettings extends AbstractCodecSettings
{
    /**
     * @param ?string $serviceNamespace Service namespace for codec consumers.
     * @param ?TimestampFormat $defaultTimestampFormat Fallback timestamp
     *                                                format.
     * @param bool $honorXmlName Whether to honor `xmlName` traits.
     * @param bool $capitalizeResolvedWireNames Whether to capitalize
     *     resolved wire names.
     * @param bool $alwaysFlattenLists Whether to flatten all list members.
     * @param bool $supportsMaps Whether map members are supported.
     * @param bool $honorTimestampFormatTrait Whether to honor
     *                                        `timestampFormat`.
     */
    public function __construct(
        ?string $serviceNamespace = null,
        ?TimestampFormat $defaultTimestampFormat = null,
        public readonly bool $honorXmlName = true,
        public readonly bool $capitalizeResolvedWireNames = false,
        public readonly bool $alwaysFlattenLists = false,
        public readonly bool $supportsMaps = true,
        bool $honorTimestampFormatTrait = true
    ) {
        parent::__construct(
            $serviceNamespace,
            $defaultTimestampFormat,
            $honorTimestampFormatTrait
        );
    }
}
