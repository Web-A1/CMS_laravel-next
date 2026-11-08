<?php

namespace App\Services\Support;

class SupportSmtpFailure extends \RuntimeException
{
    public function __construct(public string $safeCode, public bool $retryable, public bool $unknown = false, ?\Throwable $previous = null)
    {
        parent::__construct($safeCode, previous: $previous);
    }
}
