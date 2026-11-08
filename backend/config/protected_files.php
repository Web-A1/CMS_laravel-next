<?php

return [
    'source_disk' => env('PROTECTED_FILES_SOURCE_DISK', 'local'),
    'output_disk' => env('PROTECTED_FILES_OUTPUT_DISK', 'local'),
    'source_prefix' => 'protected-sources',
    'output_prefix' => 'protected-copies',
    'watermark_brand' => 'Christmedschool',
    'temporary_url_ttl_seconds' => 120,
    'max_source_ingest_bytes' => 300 * 1024 * 1024,
    'max_source_bytes' => (int) env('PROTECTED_FILES_MAX_SOURCE_BYTES', 300 * 1024 * 1024),
    'max_output_bytes' => 400 * 1024 * 1024,
    'max_pages' => 500,
    'pdf_source_normalizer' => env('PROTECTED_FILES_PDF_SOURCE_NORMALIZER', 'qpdf'),
    'qpdf_binary' => env('PROTECTED_FILES_QPDF_BINARY', 'qpdf'),
    'qpdf_timeout_seconds' => (int) env('PROTECTED_FILES_QPDF_TIMEOUT_SECONDS', 120),
    'lease_ttl_seconds' => 300,
    'pending_retry_seconds' => 30,
    'max_attempts' => 3,
];
