<?php

namespace App\Jobs;

use App\Services\Reviews\ReviewImageRemoval;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeleteReviewImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public int $timeout = 80;

    public array $backoff = [10, 30, 60, 300];

    public function __construct(public readonly int $deletionId) {}

    public function handle(ReviewImageRemoval $removal): void
    {
        $removal->run($this->deletionId);
    }
}
