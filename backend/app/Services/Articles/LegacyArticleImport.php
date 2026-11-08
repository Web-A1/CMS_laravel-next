<?php

namespace App\Services\Articles;

use App\Models\ArticleMediaAsset;
use App\Models\ContentAuthor;
use App\Models\ContentCategory;
use App\Models\ContentEntry;
use App\Models\ContentPlacement;
use App\Models\LegacyImportMapping;
use App\Models\LegacyRouteMapping;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class LegacyArticleImport
{
    private array $files = [];

    private array $media = [];

    private array $mediaProvenance = [];

    private array $routes = [];

    private array $unresolved = [];

    public function run(string $source, string $mediaRoot, string $manifest, bool $apply): array
    {
        return app(ArticleMediaGuard::class)->run(fn () => $this->execute($source, $mediaRoot, $manifest, $apply));
    }

    private function execute(string $source, string $mediaRoot, string $manifest, bool $apply): array
    {
        $data = json_decode(file_get_contents($source), true, flags: JSON_THROW_ON_ERROR);
        $this->files = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR)['files'] ?? throw new RuntimeException('Missing manifest files');
        $this->media = $this->mediaProvenance = $this->routes = $this->unresolved = [];
        foreach (['news', 'pages', 'files', 'tags'] as $key) {
            if (! isset($data[$key]) || ! is_array($data[$key])) {
                throw new RuntimeException('Missing dataset '.$key);
            }
            $ids = array_column($data[$key], 'id');
            if (count($ids) !== count($data[$key]) || count($ids) !== count(array_unique($ids))) {
                throw new RuntimeException('Duplicate/missing source IDs '.$key);
            }
        }
        $categories = $authors = $articles = [];
        $this->routes['/news'] = '/articles';
        foreach ($data['pages'] as $row) {
            if ((int) $row['parent'] === 1116) {
                $slug = basename($row['url']);
                $categories[$row['id']] = ['raw' => $row, 'fields' => ['content_type' => 'article', 'slug' => $slug, 'title' => trim($row['h1'] ?: $row['menuname']), 'sort_order' => (int) $row['menuindex'], 'is_active' => (bool) $row['published'] && ! $row['deleted']]];
                $this->routes['/'.ltrim($row['url'], '/')] = '/articles?category='.rawurlencode($slug);
            } elseif ((int) $row['parent'] === 1118) {
                $authors[$row['id']] = ['raw' => $row, 'fields' => ['slug' => basename($row['url']), 'name' => trim($row['menuname']), 'bio' => trim(strip_tags(html_entity_decode($row['content'], ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 'photo_path' => $this->asset('/upload/'.$row['image'], $mediaRoot)]];
                $this->routes['/'.ltrim($row['url'], '/')] = '/about';
            }
        }
        $slugs = [];
        foreach ($data['news'] as $row) {
            $slug = $this->slug($row['url']);
            if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) || strlen($slug) > 120) {
                throw new RuntimeException('Invalid article slug '.$row['id']);
            }
            if (isset($slugs[$slug]) || in_array($slug, ['categories', 'redirect'], true)) {
                throw new RuntimeException('Duplicate/reserved article slug '.$slug);
            }
            $slugs[$slug] = true;
            $this->routes['/news/'.ltrim($row['url'], '/')] = '/articles/'.$slug;
            if (filled($row['url_old'])) {
                $this->routes['/news/'.ltrim($row['url_old'], '/')] = '/articles/'.$slug;
            }
        }
        $attachments = [];
        foreach ($data['files'] as $file) {
            if (! $file['isRemoved']) {
                if ($file['item_id'] && strtolower($file['original_extension']) === 'pdf') {
                    $path = $this->asset($file['dir'], $mediaRoot);
                    $attachments[(int) $file['item_id']][] = ['url' => '/storage/'.$path, 'name' => $file['original_name'], 'bytes' => (int) $file['weight'], 'mime' => 'application/pdf'];
                }
            }
        }
        foreach ($data['news'] as $row) {
            $body = $this->rewrite($row['content'], $mediaRoot);
            $articles[$row['id']] = ['raw' => $row, 'fields' => [
                'content_type' => 'article', 'slug' => $this->slug($row['url']), 'title' => trim($row['h1'] ?: $row['menuname']),
                'body' => app(ArticleHtml::class)->sanitize($body), 'body_format' => 'html', 'excerpt' => mb_substr(app(ArticleHtml::class)->plainText($body), 0, 300),
                'status' => $row['isDeleted'] ? 'archived' : ($row['isPublished'] ? 'published' : 'draft'),
                'published_at' => $this->date($row['dateCreated']), 'content_updated_at' => $this->date($row['dateEdited'] ?: $row['dateCreated']),
                'seo_title' => trim($row['title'] ?? '') ?: null, 'seo_description' => mb_substr(trim($row['description'] ?? ''), 0, 500) ?: null,
                'cover_path' => $this->asset('/upload/'.$row['image'], $mediaRoot), 'cover_alt' => trim($row['h1'] ?: $row['menuname']),
                'tags' => [],
                'payload' => ['attachments' => $attachments[(int) $row['id']] ?? [], 'legacy' => ['id' => $row['id'], 'url' => $row['url'], 'viewsCounter' => $row['viewsCounter'], 'likesCounter' => $row['likesCounter'], 'keywords' => $row['keywords'], 'category2' => $row['category2'], 'category3' => $row['category3']]],
            ]];
            if (filled($row['category1']) && ! isset($categories[(int) $row['category1']])) {
                throw new RuntimeException('Unmapped category '.$row['category1']);
            }
            if (filled($row['postedById']) && ! isset($authors[(int) $row['postedById']])) {
                throw new RuntimeException('Unmapped author '.$row['postedById']);
            }
        }
        $result = ['mode' => $apply ? 'apply' : 'dry-run', 'articles' => count($articles), 'categories' => count($categories), 'authors' => count($authors), 'files' => count($data['files']), 'tags' => count($data['tags']), 'media' => count($this->media), 'created' => 0, 'skipped' => 0, 'unresolvedLinks' => array_keys($this->unresolved)];
        DB::transaction(function () use ($apply, $categories, $authors, $articles, $data, &$result) {
            $preflight = $result;
            foreach ([['pages_category', $categories, ContentCategory::class], ['pages_author', $authors, ContentAuthor::class], ['news', $articles, ContentEntry::class]] as [$table,$records,$class]) {
                foreach ($records as $id => $record) {
                    $this->record($table, $id, $class, $record, false, $preflight);
                }
            }
            foreach (['files', 'tags'] as $table) {
                foreach ($data[$table] as $row) {
                    $this->provenance($table, (int) $row['id'], $row, false);
                }
            }
            foreach ($this->mediaProvenance as $legacyPath => $mediaRecord) {
                $this->provenance('article_media', hash('sha256', $legacyPath), $mediaRecord, false);
            }
            foreach ($this->routes as $from => $to) {
                $existing = LegacyRouteMapping::query()->where('legacy_path', $from)->first();
                if ($existing && $existing->target_path !== $to) {
                    throw new RuntimeException('Route conflict '.$from);
                }
            }
            if ($apply) {
                foreach ($this->media as $path => $sourceFile) {
                    app(ArticleMedia::class)->ingest($sourceFile, ['kind' => 'legacy', 'batch' => 'articles-20261021', 'source' => array_keys(array_filter($this->mediaProvenance, fn ($record) => $record['key'] === $path))]);
                }
            }
            $categoryIds = $authorIds = [];
            foreach ($categories as $id => $record) {
                $categoryIds[$id] = $this->record('pages_category', $id, ContentCategory::class, $record, $apply, $result)?->id;
            }
            foreach ($authors as $id => $record) {
                $authorIds[$id] = $this->record('pages_author', $id, ContentAuthor::class, $record, $apply, $result)?->id;
            }
            foreach ($articles as $id => $record) {
                $record['fields']['content_category_id'] = $categoryIds[(int) $record['raw']['category1']] ?? null;
                $record['fields']['content_author_id'] = $authorIds[(int) $record['raw']['postedById']] ?? null;
                $entry = $this->record('news', $id, ContentEntry::class, $record, $apply, $result);
                if ($apply) {
                    ContentPlacement::query()->firstOrCreate(['content_entry_id' => $entry->id, 'placement' => ContentPlacement::ARTICLES_INDEX], ['is_enabled' => true]);
                }
            }
            foreach (['files', 'tags'] as $table) {
                foreach ($data[$table] as $row) {
                    $this->provenance($table, (int) $row['id'], $row, $apply);
                }
            }
            foreach ($this->mediaProvenance as $legacyPath => $mediaRecord) {
                $this->provenance('article_media', hash('sha256', $legacyPath), $mediaRecord, $apply);
            }
            foreach ($this->routes as $from => $to) {
                $existing = LegacyRouteMapping::query()->where('source_surface', 'public')->where('legacy_path', $from)->first();
                if ($existing && $existing->target_path !== $to) {
                    throw new RuntimeException('Route conflict '.$from);
                }
                if ($apply && ! $existing) {
                    LegacyRouteMapping::query()->create(['legacy_path' => $from, 'target_path' => $to, 'source_surface' => 'public', 'route_kind' => 'public', 'action' => 'redirect', 'parity_status' => 'mapped', 'requires_auth' => false, 'metadata' => ['import' => 'articles-v1']]);
                }
            }
        });

        return $result;
    }

    private function date(?string $value): ?string
    {
        return filled($value) ? CarbonImmutable::parse($value, 'Europe/Moscow')->utc()->format('Y-m-d H:i:s') : null;
    }

    private function slug(string $url): string
    {
        $name = preg_replace('/\.html$/', '', basename($url));
        if (ctype_digit($name)) {
            $name = basename(dirname($url)).'-'.$name;
        }

        return trim(preg_replace('/-+/', '-', $name), '-');
    }

    private function asset(string $legacyPath, string $root): ?string
    {
        if (in_array(basename($legacyPath), ['', 'NULL', 'null'], true)) {
            return null;
        }
        $file = $this->files[$legacyPath] ?? throw new RuntimeException('Missing referenced asset '.$legacyPath);
        $source = realpath($root.'/'.$file['file']);
        $base = realpath($root);
        if (! $base || ! $source || ! str_starts_with($source, $base.DIRECTORY_SEPARATOR) || ! is_file($source) || filesize($source) !== (int) $file['bytes'] || hash_file('sha256', $source) !== $file['sha256']) {
            throw new RuntimeException('Media verification failed '.$legacyPath);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source);
        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/avif' => 'avif', 'image/gif' => 'gif', 'application/pdf' => 'pdf'][$mime] ?? throw new RuntimeException('Unsupported asset MIME '.$legacyPath.':'.$mime);
        $path = 'article-media/'.$file['sha256'].'.'.$extension;
        $this->media[$path] = $source;
        $existing = ArticleMediaAsset::query()->where('filename', basename($path))->first();
        if ($existing && $existing->status !== 'active') {
            throw new RuntimeException('Import cannot attach deleted/deleting article media '.$legacyPath);
        }
        $this->mediaProvenance[$legacyPath] = ['legacyPath' => $legacyPath, 'disk' => $existing?->disk ?? config('articles.media_disk'), 'key' => $path, 'sha256' => $file['sha256'], 'bytes' => (int) $file['bytes'], 'mime' => $mime];

        return $path;
    }

    private function rewrite(string $html, string $root): string
    {
        return preg_replace_callback('~\b(src|href)\s*=\s*([\x22\x27])(.*?)\2~is', function ($m) use ($root) {
            $url = html_entity_decode($m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $parsed = parse_url($url);
            $local = ! isset($parsed['host']) || in_array(strtolower($parsed['host']), ['christmedschool.com', 'www.christmedschool.com'], true);
            $path = $parsed['path'] ?? '';
            if ($local && (str_starts_with($path, '/upload/') || str_starts_with($path, '/new_upload/'))) {
                $url = '/storage/'.$this->asset($path, $root);
            } elseif ($local && isset($this->routes[$path])) {
                $url = $this->routes[$path].(isset($parsed['fragment']) ? '#'.$parsed['fragment'] : '');
            } elseif ($local && strtolower($m[1]) === 'href' && $path !== '') {
                $this->unresolved[$url] = true;
                if (! isset($parsed['scheme']) && ! isset($parsed['host'])) {
                    $url = 'https://christmedschool.com/'.ltrim($url, '/');
                }
            }

            return $m[1].'='.$m[2].htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8').$m[2];
        }, $html);
    }

    private function mapping(string $table, int|string $id): ?LegacyImportMapping
    {
        return LegacyImportMapping::query()->where('source_table', $table)->where('source_id', (string) $id)->where('target_domain', 'articles')->lockForUpdate()->first();
    }

    private function record(string $table, int $id, string $class, array $record, bool $apply, array &$result): ?Model
    {
        $hash = hash('sha256', json_encode($record['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $mapping = $this->mapping($table, $id);
        if ($mapping) {
            $target = $class::query()->lockForUpdate()->find($mapping->target_id);
            if (! $target || $mapping->source_fingerprint_hash !== $hash || ($mapping->metadata['targetHash'] ?? null) !== $this->targetHash($target)) {
                throw new RuntimeException('Import/editor conflict '.$table.':'.$id);
            }
            if ($target instanceof ContentEntry && ! ContentPlacement::query()->where('content_entry_id', $target->id)->where('placement', ContentPlacement::ARTICLES_INDEX)->where('is_enabled', true)->exists()) {
                throw new RuntimeException('Placement conflict '.$id);
            }
            $result['skipped']++;

            return $target;
        }
        $collision = $class::query()->where('slug', $record['fields']['slug'])->when($class !== ContentAuthor::class, fn ($q) => $q->where('content_type', 'article'))->exists();
        if ($collision) {
            throw new RuntimeException('Slug collision '.$record['fields']['slug']);
        }
        $result['created']++;
        if (! $apply) {
            return null;
        }
        $target = $class::query()->create($record['fields']);
        LegacyImportMapping::query()->create($this->mappingFields($table, $id, $hash) + ['target_type' => $class, 'target_id' => $target->id, 'metadata' => ['source' => $record['raw'], 'targetHash' => $this->targetHash($target->fresh())]]);

        return $target;
    }

    private function targetHash(Model $target): string
    {
        $fields = $target->getAttributes();
        unset($fields['created_at'],$fields['updated_at']);
        if ($target instanceof ContentEntry && ($fields['subject_id'] ?? null) === null) {
            unset($fields['subject_id']);
        }
        if ($target instanceof ContentAuthor && ($fields['user_id'] ?? null) === null) {
            unset($fields['user_id']);
        }
        ksort($fields);

        return hash('sha256', json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function mappingFields(string $table, int|string $id, string $hash): array
    {
        return ['batch_key' => 'articles-20261021', 'source_table' => $table, 'source_id' => (string) $id, 'source_scope_type' => 'id_only', 'source_scope_id' => '0', 'target_domain' => 'articles', 'mapping_status' => 'mapped', 'validation_status' => 'passed', 'idempotency_key' => hash('sha256', 'articles-v1:'.$table.':'.$id), 'source_fingerprint_hash' => $hash];
    }

    private function provenance(string $table, int|string $id, array $row, bool $apply): void
    {
        $hash = hash('sha256', json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $mapping = $this->mapping($table, $id);
        if ($mapping && $mapping->source_fingerprint_hash !== $hash) {
            throw new RuntimeException('Provenance conflict '.$table.':'.$id);
        }
        if ($apply && ! $mapping) {
            LegacyImportMapping::query()->create($this->mappingFields($table, $id, $hash) + ['target_type' => 'provenance', 'target_id' => null, 'metadata' => ['source' => $row]]);
        }
    }
}
