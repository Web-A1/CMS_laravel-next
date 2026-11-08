<?php

namespace App\Jobs;

use App\Services\Support\SupportMediaRemoval;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeleteSupportMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    public array $backoff = [30, 120, 300];

    public function __construct(public readonly int $deletionId) {}

    public function handle(SupportMediaRemoval $removal): void
    {
        $removal->run($this->deletionId);
    }
}
