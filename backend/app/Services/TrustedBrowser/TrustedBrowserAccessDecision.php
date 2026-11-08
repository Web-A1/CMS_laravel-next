<?php

namespace App\Services\TrustedBrowser;

final readonly class TrustedBrowserAccessDecision
{
    public function __construct(
        public string $mode,
        public string $state,
        public ?string $code,
        public ?string $message,
    ) {}

    public function shouldBlock(): bool
    {
        return $this->mode === 'enforce' && $this->state !== 'trusted';
    }
}
