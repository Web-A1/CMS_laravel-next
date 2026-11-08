<?php

namespace App\Jobs;

use App\Services\ProtectedFiles\ProtectedFileGenerator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateProtectedFileDeliveryJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public int $timeout = 240;

    public function __construct(public readonly int $deliveryId) {}

    public function handle(ProtectedFileGenerator $generator): void
    {
        $generator->generate($this->deliveryId);
    }
}
