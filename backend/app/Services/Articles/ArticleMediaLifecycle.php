<?php

namespace App\Services\Articles;

use App\Jobs\DeleteArticleMedia;
use App\Models\ArticleMediaAsset;
use App\Models\ContentAuthor;
use App\Models\ContentEntry;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class ArticleMediaLifecycle
{
    public function hashes(array $values, bool $validateOwnedPaths = false): array
    {
        $result = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }
            $urls = [$value];
            if (str_contains($value, '<')) {
                $urls = [];
                $dom = new \DOMDocument;
                $previous = libxml_use_internal_errors(true);
                $dom->loadHTML('<?xml encoding="utf-8"?>'.$value, LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
                foreach (['img' => ['src', 'srcset', 'data-full-src'], 'source' => ['src', 'srcset'], 'a' => ['href']] as $tag => $attributes) {
                    foreach ($dom->getElementsByTagName($tag) as $node) {
                        foreach ($attributes as $attribute) {
                            $attributeValue = $node->getAttribute($attribute);
                            if ($attribute === 'srcset') {
                                foreach (explode(',', $attributeValue) as $candidate) {
                                    $urls[] = preg_split('/\s+/', trim($candidate))[0];
                                }
                            } else {
                                $urls[] = $attributeValue;
                            }
                        }
                    }
                }
            }
            foreach ($urls as $url) {
                $path = $this->ownedPath($url, $validateOwnedPaths);
                if ($path && preg_match('~^article-media/(?:([a-f0-9]{64})\.(?:jpe?g|png|webp|avif|gif|pdf)|variants/([a-f0-9]{64})/v1/[0-9]+\.(?:avif|webp))$~D', $path, $match)) {
                    $result[] = $match[1] ?: $match[2];
                }
            }
        }

        return array_values(array_unique($result));
    }

    public function ownedPath(string $url, bool $validate = false): ?string
    {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (! preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) || preg_match('~^https?:~i', $url)) {
            $url = str_replace('\\', '/', $url);
        }
        try {
            $uri = new Uri($url);
        } catch (\InvalidArgumentException) {
            return null;
        }
        // URL consumers decode percent escapes once. Never decode the host or a second layer.
        $path = UriResolver::removeDotSegments(rawurldecode($uri->getPath()));
        if ($uri->getHost() === '') {
            if ($uri->getScheme() !== '') {
                return null;
            }
            if (str_starts_with($path, 'article-media/')) {
                return $this->validatedPath($path, $validate);
            }

            return str_starts_with($path, '/storage/article-media/') ? $this->validatedPath(substr($path, 9), $validate) : null;
        }
        foreach ([rtrim(config('app.url'), '/').'/storage', rtrim(config('app.frontend_url') ?? '', '/').'/storage', config('filesystems.disks.public.url'), config('filesystems.disks.article-media-s3.url')] as $base) {
            if (! $base) {
                continue;
            }
            $allowed = new Uri($base);
            $candidate = $uri->getScheme() === '' ? $uri->withScheme($allowed->getScheme()) : $uri;
            if ($candidate->getHost() !== $allowed->getHost() || $candidate->getPort() !== $allowed->getPort()) {
                continue;
            }
            $prefix = rtrim(UriResolver::removeDotSegments(rawurldecode($allowed->getPath())), '/').'/';
            if (str_starts_with($path, $prefix.'article-media/')) {
                return $this->validatedPath(substr($path, strlen($prefix)), $validate);
            }
        }

        return null;
    }

    private function validatedPath(string $path, bool $validate): ?string
    {
        if (preg_match('~^article-media/(?:[a-f0-9]{64}\.(?:jpe?g|png|webp|avif|gif|pdf)|variants/[a-f0-9]{64}/v1/[0-9]+\.(?:avif|webp))$~D', $path)) {
            return $path;
        }
        if ($validate) {
            throw ValidationException::withMessages(['body' => 'Недопустимая ссылка на медиа статьи. Используйте адрес файла из редактора.']);
        }

        return null;
    }

    public function entryHashes(ContentEntry $entry): array
    {
        return $this->hashes([$entry->cover_path, $entry->body, ...array_column($entry->payload['attachments'] ?? [], 'url')]);
    }

    public function referencedHashes(): array
    {
        $hashes = [];
        foreach (ContentEntry::query()->select(['id', 'cover_path', 'body', 'payload'])->cursor() as $entry) {
            foreach ($this->entryHashes($entry) as $hash) {
                $hashes[$hash] = true;
            }
        }
        foreach (ContentAuthor::query()->select(['photo_path', 'avatar_path'])->cursor() as $author) {
            foreach ($this->hashes([$author->photo_path, $author->avatar_path]) as $hash) {
                $hashes[$hash] = true;
            }
        }

        return $hashes;
    }

    public function assertAttachable(array $hashes): void
    {
        $active = ArticleMediaAsset::query()->whereIn('sha256', $hashes)->where('status', 'active')->orderBy('id')->lockForUpdate()->pluck('sha256')->all();
        if (array_diff($hashes, $active)) {
            throw ValidationException::withMessages(['body' => 'Один из файлов уже удаляется или удалён. Загрузите его заново.']);
        }
    }

    public function scheduleUnused(array $hashes, ?int $actorId = null): void
    {
        $referenced = $this->referencedHashes();
        foreach (ArticleMediaAsset::query()->whereIn('sha256', $hashes)->where('status', 'active')->orderBy('id')->lockForUpdate()->get() as $asset) {
            if (isset($referenced[$asset->sha256])) {
                continue;
            }
            $manifest = ['disk' => $asset->disk, 'original' => $asset->key, 'variantPrefix' => 'article-media/variants/'.$asset->sha256.'/v1/', 'variants' => array_column($asset->variants ?? [], 'key')];
            $asset->update(['status' => 'deleting']);
            DB::table('article_media_deletions')->updateOrInsert(['asset_id' => $asset->id, 'generation' => $asset->generation], ['manifest' => json_encode($manifest), 'status' => 'pending', 'attempts' => 0, 'retry_at' => null, 'error' => null, 'completed_at' => null, 'created_at' => now(), 'updated_at' => now()]);
            $id = DB::table('article_media_deletions')->where('asset_id', $asset->id)->where('generation', $asset->generation)->value('id');
            $this->event($asset, 'deletion_scheduled', $actorId);
            DB::afterCommit(function () use ($id): void {
                try {
                    DeleteArticleMedia::dispatch($id);
                } catch (Throwable $e) {
                    report($e);
                }
            });
        }
    }

    public function cleanupAbandoned(int $limit = 50): int
    {
        return app(ArticleMediaGuard::class)->run(function () use ($limit) {
            $referenced = array_keys($this->referencedHashes());
            $hashes = ArticleMediaAsset::query()->where('status', 'active')->whereNotIn('sha256', $referenced)->where(function ($query) {
                $query->where('lease_at', '<', now()->subHours(config('articles.upload_ttl_hours')))->orWhere(function ($query) {
                    $query->whereNull('lease_at')->where('created_at', '<', now()->subHours(config('articles.upload_ttl_hours')));
                });
            })->orderBy('id')->limit($limit)->pluck('sha256')->all();
            $before = DB::table('article_media_deletions')->count();
            $this->scheduleUnused($hashes);

            return DB::table('article_media_deletions')->count() - $before;
        });
    }

    public function delete(int $id): void
    {
        app(ArticleMediaGuard::class)->run(function () use ($id): void {
            $deletion = DB::table('article_media_deletions')->where('id', $id)->first();
            if (! $deletion || $deletion->status !== 'pending' || ($deletion->retry_at && now()->lt($deletion->retry_at))) {
                return;
            }
            $asset = ArticleMediaAsset::query()->lockForUpdate()->findOrFail($deletion->asset_id);
            if ($asset->generation !== (int) $deletion->generation || $asset->status !== 'deleting') {
                DB::table('article_media_deletions')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);

                return;
            }
            if (isset($this->referencedHashes()[$asset->sha256])) {
                $asset->update(['status' => 'active']);
                DB::table('article_media_deletions')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
                $this->event($asset, 'deletion_cancelled');

                return;
            }
            $manifest = json_decode($deletion->manifest, true, flags: JSON_THROW_ON_ERROR);
            $prefix = 'article-media/variants/'.$asset->sha256.'/v1/';
            if ($manifest['disk'] !== $asset->disk || $manifest['original'] !== $asset->key || $asset->key !== 'article-media/'.$asset->filename || $manifest['variantPrefix'] !== $prefix) {
                throw new RuntimeException('Invalid article deletion manifest.');
            }
            try {
                $storage = Storage::disk($asset->disk);
                $paths = array_values(array_unique([$asset->key, ...($manifest['variants'] ?? []), ...$storage->allFiles(rtrim($prefix, '/'))]));
                foreach ($paths as $path) {
                    if ($path !== $asset->key && ! preg_match('~^'.preg_quote($prefix, '~').'[0-9]+\.(avif|webp)$~D', $path)) {
                        throw new RuntimeException('Invalid variant deletion path.');
                    }
                    if ($storage->exists($path) && (! $storage->delete($path) || $storage->exists($path))) {
                        throw new RuntimeException('Article media delete not confirmed.');
                    }
                }
                $asset->update(['status' => 'deleted', 'deleted_at' => now(), 'variants' => []]);
                DB::table('article_media_deletions')->where('id', $id)->update(['status' => 'completed', 'attempts' => $deletion->attempts + 1, 'error' => null, 'completed_at' => now(), 'updated_at' => now()]);
                $this->event($asset, 'deleted', null, ['files' => $paths]);
            } catch (Throwable $e) {
                DB::table('article_media_deletions')->where('id', $id)->update(['attempts' => $deletion->attempts + 1, 'retry_at' => now()->addMinutes(5), 'error' => mb_substr($e->getMessage(), 0, 2000), 'updated_at' => now()]);
                $this->event($asset, 'deletion_failed', null, ['error' => mb_substr($e->getMessage(), 0, 2000)]);
            }
        });
    }

    public function event(ArticleMediaAsset $asset, string $event, ?int $actorId = null, array $metadata = []): void
    {
        DB::table('article_media_events')->insert(['asset_id' => $asset->id, 'generation' => $asset->generation, 'event' => $event, 'actor_id' => $actorId, 'metadata' => json_encode($metadata), 'created_at' => now()]);
    }
}
