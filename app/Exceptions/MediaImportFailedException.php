<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A worker-time import failure with a user-facing reason code
 * (see MediaImport::REASON_*) and an admin-only technical detail.
 */
class MediaImportFailedException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?string $detail = null,
    ) {
        parent::__construct($detail ?? $reason);
    }
}
