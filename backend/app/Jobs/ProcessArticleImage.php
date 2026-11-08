<?php

namespace App\Jobs;

use App\Services\Articles\ArticleMediaVariants;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessArticleImage implements ShouldQueue
{
    use Queueable;

    public int $timeout = 110;

    public function __construct(public int $assetId, public int $generation) {}

    public function handle(ArticleMediaVariants $variants): void
    {
        $variants->process($this->assetId, $this->generation);
    }
}
