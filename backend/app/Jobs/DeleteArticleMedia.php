<?php

namespace App\Jobs;

use App\Services\Articles\ArticleMediaLifecycle;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeleteArticleMedia implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $deletionId) {}

    public function handle(ArticleMediaLifecycle $lifecycle): void
    {
        $lifecycle->delete($this->deletionId);
    }
}
