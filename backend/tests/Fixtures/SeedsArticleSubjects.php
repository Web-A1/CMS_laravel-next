<?php

namespace Tests\Fixtures;

use App\Console\Commands\BackfillArticleSubjects;
use App\Models\ContentCategory;
use App\Models\ContentEntry;
use App\Models\LegacyImportMapping;
use App\Models\Subject;

trait SeedsArticleSubjects
{
    private function seedArticles(): void
    {
        foreach (BackfillArticleSubjects::MAP as $id => [$categorySlug, $subjectId, $subjectSlug, $count]) {
            $sourceCategoryId = $this->seedCategory($id, $categorySlug);
            Subject::forceCreate(['id' => $subjectId, 'slug' => $subjectSlug, 'title' => $subjectSlug, 'is_active' => true]);
            for ($i = 0; $i < $count; $i++) {
                $sourceId = $id * 1000 + $i;
                $slug = 'imported-'.$sourceId;
                $raw = ['id' => $sourceId, 'url' => $i === 0 ? '/imported/'.$sourceId.'.html' : '/news/'.$slug.'.html', 'category1' => $sourceCategoryId];
                $entry = ContentEntry::create(['slug' => $slug, 'title' => 'Preserved '.$sourceId, 'body' => '<p>Preserved body</p>', 'content_type' => 'article', 'content_category_id' => $id, 'payload' => ['legacy' => ['id' => $sourceId]], 'content_updated_at' => '2026-10-01 12:00:00']);
                $fields = $entry->fresh()->getAttributes();
                unset($fields['created_at'], $fields['updated_at'], $fields['subject_id']);
                ksort($fields);
                LegacyImportMapping::create(['batch_key' => 'articles-20261021', 'source_table' => 'news', 'source_id' => (string) $sourceId,
                    'source_scope_type' => 'id_only', 'source_scope_id' => '0', 'target_domain' => 'articles',
                    'target_type' => ContentEntry::class, 'target_id' => $entry->id, 'mapping_status' => 'mapped', 'validation_status' => 'passed',
                    'idempotency_key' => hash('sha256', 'articles-v1:news:'.$sourceId),
                    'source_fingerprint_hash' => hash('sha256', json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                    'metadata' => ['source' => $raw, 'targetHash' => hash('sha256', json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))]]);
            }
        }
        foreach ([3 => 7, 4 => 72] as $id => $count) {
            $sourceCategoryId = $this->seedCategory($id, 'general-'.$id);
            for ($i = 0; $i < $count; $i++) {
                $sourceId = $id * 1000 + $i;
                $slug = 'general-'.$id.'-'.$i;
                $raw = ['id' => $sourceId, 'url' => '/news/'.$slug, 'category1' => $sourceCategoryId];
                $entry = ContentEntry::create(['slug' => $slug, 'title' => 'General', 'body' => 'General body', 'content_type' => 'article', 'content_category_id' => $id, 'payload' => ['legacy' => ['id' => $sourceId]]]);
                LegacyImportMapping::create(['batch_key' => 'articles-20261021', 'source_table' => 'news', 'source_id' => (string) $sourceId,
                    'source_scope_type' => 'id_only', 'source_scope_id' => '0', 'target_domain' => 'articles',
                    'target_type' => ContentEntry::class, 'target_id' => $entry->id, 'mapping_status' => 'mapped', 'validation_status' => 'passed',
                    'idempotency_key' => hash('sha256', 'articles-v1:news:'.$sourceId),
                    'source_fingerprint_hash' => hash('sha256', json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                    'metadata' => ['source' => $raw]]);
            }
        }
        $this->seedCategory(16, 'vakansii');
    }

    private function seedCategory(int $id, string $slug): int
    {
        $sourceId = [1 => 1117, 2 => 1122, 3 => 1131][$id] ?? 2000 + $id;
        ContentCategory::forceCreate(['id' => $id, 'slug' => $slug, 'title' => $slug, 'content_type' => 'article']);
        $raw = ['id' => $sourceId, 'parent' => 1116, 'url' => 'news/'.$slug];
        LegacyImportMapping::create(['batch_key' => 'articles-20261021', 'source_table' => 'pages_category', 'source_id' => (string) $sourceId,
            'source_scope_type' => 'id_only', 'source_scope_id' => '0', 'target_domain' => 'articles',
            'target_type' => ContentCategory::class, 'target_id' => $id, 'mapping_status' => 'mapped', 'validation_status' => 'passed',
            'idempotency_key' => hash('sha256', 'articles-v1:pages_category:'.$sourceId),
            'source_fingerprint_hash' => hash('sha256', json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), 'metadata' => ['source' => $raw]]);

        return $sourceId;
    }
}
