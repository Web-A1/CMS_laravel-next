<?php

namespace App\Services\TrustedBrowser;

use RuntimeException;

final class TrustedBrowserException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus,
        public readonly array $meta = [],
    ) {
        parent::__construct($message);
    }
}
