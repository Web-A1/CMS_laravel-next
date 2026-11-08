<?php

namespace App\Exceptions;

use RuntimeException;

class ProtectedFileGenerationException extends RuntimeException
{
    public const SOURCE_UNAVAILABLE = 'source_unavailable';

    public const SOURCE_METADATA_INVALID = 'source_metadata_invalid';

    public const SOURCE_TOO_LARGE = 'source_too_large';

    public const SOURCE_INTEGRITY_FAILED = 'source_integrity_failed';

    public const PDF_UNSUPPORTED = 'pdf_unsupported';

    public const PDF_PAGE_LIMIT_EXCEEDED = 'pdf_page_limit_exceeded';

    public const OUTPUT_WRITE_FAILED = 'output_write_failed';

    public const OUTPUT_INTEGRITY_FAILED = 'output_integrity_failed';

    public const OUTPUT_TOO_LARGE = 'output_too_large';

    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
