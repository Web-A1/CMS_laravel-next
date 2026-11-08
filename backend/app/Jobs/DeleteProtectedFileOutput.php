<?php

namespace App\Jobs;

use App\Services\ProtectedFiles\ProtectedFileOutputRemoval;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class DeleteProtectedFileOutput implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $deletionId) {}

    public function handle(ProtectedFileOutputRemoval $removal): void
    {
        $removal->run($this->deletionId);
    }
}
