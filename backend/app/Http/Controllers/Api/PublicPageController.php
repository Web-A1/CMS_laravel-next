<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicPageResource;
use App\Models\ContentEntry;
use App\Models\ContentPlacement;

class PublicPageController extends Controller
{
    public function about(): PublicPageResource
    {
        $entry = ContentEntry::query()
            ->select('content_entries.*')
            ->selectRaw('content_placements.sort_order as placement_sort_order')
            ->page()
            ->published()
            ->join('content_placements', function ($join): void {
                $join
                    ->on('content_placements.content_entry_id', '=', 'content_entries.id')
                    ->where('content_placements.placement', ContentPlacement::ABOUT_PAGE)
                    ->where('content_placements.is_enabled', true);
            })
            ->where('content_entries.slug', 'about')
            ->orderBy('content_placements.sort_order')
            ->orderBy('content_entries.sort_order')
            ->orderBy('content_entries.id')
            ->firstOrFail();

        return new PublicPageResource($entry);
    }
}
