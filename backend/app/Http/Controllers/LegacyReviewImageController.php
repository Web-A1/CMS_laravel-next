<?php

namespace App\Http\Controllers;

use App\Models\ReviewImageMigration;
use App\Services\Reviews\ExistingReviewImages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LegacyReviewImageController extends Controller
{
    public function __invoke(Request $request, ExistingReviewImages $images, ?string $path = null): RedirectResponse
    {
        $url = $path !== null ? '/storage/reviews/'.$path : $request->query('path');
        abort_unless(is_string($url) && strlen($url) <= 1500 && $images->isLegacyUrl($url), 404);
        $entry = ReviewImageMigration::with('asset')->where('source_url', $url)->whereNotNull('switched_at')->first();
        abort_unless($entry?->asset?->status === 'ready', 404);

        return redirect()->away($entry->asset->url(), 301)->header('Cache-Control', 'public, max-age=86400');
    }
}
