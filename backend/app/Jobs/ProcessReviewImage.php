<?php

namespace App\Jobs;

use App\Models\ReviewImageAsset;
use App\Services\Reviews\ReviewImageAssets;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessReviewImage implements ShouldQueue
{
    use Queueable;

    public int $timeout = 80;

    public int $tries = 10;

    public array $backoff = [10, 30, 60];

    public function __construct(public readonly string $assetId) {}

    public function handle(ReviewImageAssets $assets): void
    {
        $assets->process($this->assetId);
    }

    public function failed(?\Throwable $exception): void
    {
        ReviewImageAsset::whereKey($this->assetId)->whereIn('status', ['pending', 'processing'])
            ->update(['status' => 'failed', 'error' => 'Подготовка не завершена. Удалите файл из формы и загрузите его повторно.']);
    }
}
