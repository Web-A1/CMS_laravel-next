<?php

namespace App\Exceptions;

use RuntimeException;

class ProtectedFileSourceImportException extends RuntimeException
{
    public const INVALID_OPTIONS = 'invalid_options';

    public const INVALID_CONFIRMATION = 'invalid_confirmation';

    public const PLAN_MISMATCH = 'plan_mismatch';

    public const MATERIAL_NOT_FOUND = 'material_not_found';

    public const MATERIAL_NOT_PDF = 'material_not_pdf';

    public const UNSAFE_PATH = 'unsafe_path';

    public const UNSAFE_PERMISSIONS = 'unsafe_permissions';

    public const SOURCE_TOO_LARGE = 'source_too_large';

    public const SOURCE_UNSTABLE = 'source_unstable';

    public const INVALID_PDF = 'invalid_pdf';

    public const SOURCE_EXPECTATION_MISMATCH = 'source_expectation_mismatch';

    public const INVALID_FILENAME = 'invalid_filename';

    public const INVALID_CONFIGURATION = 'invalid_configuration';

    public const STORAGE_WRITE_FAILED = 'storage_write_failed';

    public const STORAGE_INTEGRITY_FAILED = 'storage_integrity_failed';

    public const REGISTRY_CONFLICT = 'registry_conflict';

    public const CLEANUP_DEFERRED = 'cleanup_deferred';

    public const INTERNAL_ERROR = 'internal_error';

    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
