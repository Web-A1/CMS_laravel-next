<?php

namespace App\Http\Controllers;

use App\Models\ArticleMediaAsset;
use Illuminate\Support\Facades\Storage;

final class ArticleMediaController extends Controller
{
    public function __invoke(string $filename)
    {
        abort_unless(preg_match('/^[a-f0-9]{64}\.(jpg|jpeg|png|gif|webp|avif|pdf)$/D', $filename), 404);
        $asset = ArticleMediaAsset::query()->where('filename', $filename)->where('status', 'active')->firstOrFail();

        return $this->serve($asset, $asset->key, $asset->mime);
    }

    public function variant(string $sha, string $filename)
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $sha) && preg_match('/^[0-9]+\.(?:avif|webp)$/D', $filename), 404);
        $asset = ArticleMediaAsset::query()->where('sha256', $sha)->where('status', 'active')->where('processing_status', 'ready')->firstOrFail();
        $key = 'article-media/variants/'.$sha.'/v1/'.$filename;
        $variant = collect($asset->variants ?? [])->firstWhere('key', $key);
        abort_unless($variant, 404);

        return $this->serve($asset, $key, 'image/'.$variant['format']);
    }

    private function serve(ArticleMediaAsset $asset, string $key, string $mime)
    {
        $storage = Storage::disk($asset->disk);
        if (config('filesystems.disks.'.$asset->disk.'.driver') === 's3') {
            return redirect()->away($storage->url($key), 302)->header('Cache-Control', 'public, max-age=3600');
        }
        abort_unless($storage->exists($key), 404);

        return $storage->response($key, null, ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=31536000, immutable']);
    }
}
