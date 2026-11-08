<?php

namespace App\Services\Articles;

use App\Models\ArticleMediaAsset;
use App\Models\ContentAuthor;
use App\Models\ContentEntry;
use App\Models\ContentPlacement;
use App\Models\User;
use App\Support\ImpersonationContext;
use App\Support\Rbac;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ArticleEditor
{
    public const PARAMETERS = ['status', 'subject_id', 'content_author_id', 'published_at', 'cover_path'];

    public function actor(string $permission = 'articles.write'): User
    {
        $actor = auth('web')->user()?->fresh();
        abort_unless($actor instanceof User && ! $actor->blocksAuthenticationForMigration() && Rbac::hasPermission($actor, 'admin.access') && Rbac::hasPermission($actor, $permission) && ! ImpersonationContext::isActive(request()), 403);

        return $actor;
    }

    public function save(?int $id, array $input, int $revision = 0): ContentEntry
    {
        $actor = $this->actor();

        return app(ArticleMediaGuard::class)->run(function () use ($actor, $id, $input, $revision) {
            $actor = $this->actor();
            $entry = $id ? ContentEntry::query()->article()->lockForUpdate()->findOrFail($id) : new ContentEntry(['content_type' => 'article']);
            if ($id && $entry->revision !== $revision) {
                throw ValidationException::withMessages(['data.title' => 'Статья уже изменена. Обновите страницу перед сохранением.']);
            }
            $data = Validator::make($input, [
                'title' => ['required', 'string', 'max:255'], 'slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::notIn(['categories', 'redirect'])],
                'body' => ['required', 'string', 'max:2000000'], 'excerpt' => ['nullable', 'string', 'max:2000'],
                'subject_id' => ['sometimes', 'nullable', 'integer', Rule::exists('subjects', 'id')->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $entry->subject_id ?? 0))],
                'content_author_id' => ['nullable', 'exists:content_authors,id'], 'published_at' => ['nullable', 'date'],
                'status' => ['required', Rule::in(['draft', 'published', 'hidden', 'archived'])],
                'seo_title' => ['nullable', 'string', 'max:255'], 'seo_description' => ['nullable', 'string', 'max:500'],
                'cover_path' => ['nullable', 'string', 'regex:~^article-media/[a-zA-Z0-9._/-]+\.(?:jpe?g|png|webp|avif|gif)$~i', 'not_regex:~\.\.~'],
                'cover_alt' => ['nullable', 'string', 'max:255'],
            ])->validate();
            if (array_key_exists('subject_id', $data)) {
                $data['subject_id'] = $data['subject_id'] === null || $data['subject_id'] === '' ? null : (int) $data['subject_id'];
            }
            if ($id && filled($data['slug'] ?? null) && $entry->slug !== $data['slug']) {
                throw ValidationException::withMessages(['data.slug' => 'Адрес существующей статьи сохраняется.']);
            }
            $data['slug'] = $id ? $entry->slug : ($data['slug'] ?: Str::slug($data['title']));
            if (! filled($data['slug'])) {
                $data['slug'] = 'article-'.Str::lower(Str::random(12));
            }
            if (in_array($data['slug'], ['categories', 'redirect'], true)) {
                throw ValidationException::withMessages(['data.slug' => 'Этот адрес зарезервирован.']);
            }
            if (ContentEntry::query()->article()->where('slug', $data['slug'])->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) {
                throw ValidationException::withMessages(['data.slug' => 'Этот адрес уже используется.']);
            }
            if ($data['status'] === 'published' && empty($data['published_at'])) {
                throw ValidationException::withMessages(['data.published_at' => 'Укажите дату публикации.']);
            }
            $before = $entry->exists ? $entry->getAttributes() : null;
            $data['tags'] = [];
            $oldHashes = app(ArticleMediaLifecycle::class)->entryHashes($entry);
            // Filament's public attachment URL may use the local HTTP origin.
            // Persist same-origin storage paths so deployment cannot leak dev URLs.
            $data['body'] = app(ArticleHtml::class)->normalize($data['body']);
            if (blank($data['excerpt'] ?? null)) {
                $data['excerpt'] = mb_substr(app(ArticleHtml::class)->plainText($data['body']), 0, 300);
            }
            app(ArticleMedia::class)->assertRegistered($data['cover_path'] ?? null);
            app(ArticleMediaLifecycle::class)->assertAttachable(app(ArticleMediaLifecycle::class)->hashes([$data['body'], $data['cover_path'] ?? null], true));
            $data['payload'] = app(ArticleRecommendations::class)->payload($entry, $input, $data);
            $entry->fill($data + ['body_format' => 'html']);
            $entry->revision = $revision + 1;
            $entry->content_updated_at = now();
            $entry->save();
            ContentPlacement::query()->firstOrCreate(['content_entry_id' => $entry->id, 'placement' => ContentPlacement::ARTICLES_INDEX], ['is_enabled' => true]);
            $this->audit($actor, $entry, $before);
            $hashes = app(ArticleMediaLifecycle::class)->entryHashes($entry);
            ArticleMediaAsset::query()->whereIn('sha256', $hashes)->update(['lease_at' => null]);
            app(ArticleMediaLifecycle::class)->scheduleUnused($oldHashes, $actor->id);

            return $entry;
        });
    }

    public function saveParameters(int $id, array $patch, int $revision, array $draftBodyHashes = []): ContentEntry
    {
        $actor = $this->actor();
        if (array_diff(array_keys($patch), self::PARAMETERS)) {
            throw ValidationException::withMessages(['data' => 'Переданы неподдерживаемые параметры статьи.']);
        }

        return app(ArticleMediaGuard::class)->run(function () use ($actor, $id, $patch, $revision, $draftBodyHashes) {
            $actor = $this->actor();
            $entry = ContentEntry::query()->article()->lockForUpdate()->findOrFail($id);
            if ($entry->revision !== $revision) {
                throw ValidationException::withMessages(['data.revision' => 'Статья уже изменена. Обновите страницу перед сохранением.']);
            }
            $data = Validator::make($patch, [
                'status' => ['sometimes', 'required', Rule::in(['draft', 'published', 'hidden', 'archived'])],
                'subject_id' => ['sometimes', 'nullable', 'integer', Rule::exists('subjects', 'id')->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $entry->subject_id ?? 0))],
                'content_author_id' => ['sometimes', 'nullable', 'integer', 'exists:content_authors,id'],
                'published_at' => ['sometimes', 'nullable', 'date'],
                'cover_path' => ['sometimes', 'nullable', 'string', 'regex:~^article-media/[a-zA-Z0-9._/-]+\.(?:jpe?g|png|webp|avif|gif)$~i', 'not_regex:~\.\.~'],
            ])->validate();
            foreach (['subject_id', 'content_author_id'] as $key) {
                if (array_key_exists($key, $data)) {
                    $data[$key] = filled($data[$key]) ? (int) $data[$key] : null;
                }
            }
            if ((array_key_exists('status', $data) || array_key_exists('published_at', $data))
                && ($data['status'] ?? $entry->status) === 'published'
                && blank(array_key_exists('published_at', $data) ? $data['published_at'] : $entry->published_at)) {
                throw ValidationException::withMessages(['data.published_at' => 'Укажите дату публикации.']);
            }
            $before = $entry->getAttributes();
            $lifecycle = app(ArticleMediaLifecycle::class);
            $oldHashes = $lifecycle->entryHashes($entry);
            if (array_key_exists('cover_path', $data)) {
                app(ArticleMedia::class)->assertRegistered($data['cover_path']);
                try {
                    $lifecycle->assertAttachable($lifecycle->hashes([$data['cover_path']], true));
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(['data.cover_path' => collect($exception->errors())->flatten()->first()]);
                }
            }
            $entry->fill($data);
            if (! $entry->isDirty()) {
                return $entry;
            }
            $coverChanged = $entry->isDirty('cover_path');
            $entry->revision = $revision + 1;
            $entry->content_updated_at = now();
            $entry->save();
            if ($coverChanged && $draftBodyHashes) {
                $unreferenced = array_diff(array_intersect($oldHashes, $draftBodyHashes), array_keys($lifecycle->referencedHashes()));
                if ($unreferenced) {
                    throw ValidationException::withMessages(['data.cover_path' => 'Обложка используется в несохранённом тексте. Сохраните статью целиком, чтобы изменить обложку.']);
                }
            }
            $this->audit($actor, $entry, $before);
            if ($coverChanged) {
                ArticleMediaAsset::query()->whereIn('sha256', $lifecycle->hashes([$entry->cover_path]))->update(['lease_at' => null]);
                $lifecycle->scheduleUnused($oldHashes, $actor->id);
            }

            return $entry;
        });
    }

    public function delete(int $id, int $revision): void
    {
        $actor = $this->actor('articles.delete');

        app(ArticleMediaGuard::class)->run(function () use ($actor, $id, $revision): void {
            $actor = $this->actor('articles.delete');
            $entry = ContentEntry::query()->article()->lockForUpdate()->findOrFail($id);
            if ($entry->revision !== $revision) {
                throw ValidationException::withMessages(['data.title' => 'Статья уже изменена. Обновите страницу перед удалением.']);
            }
            $hashes = app(ArticleMediaLifecycle::class)->entryHashes($entry);
            DB::table('legacy_import_mappings')->where('target_domain', 'articles')->where('source_table', 'news')
                ->where('target_type', ContentEntry::class)->where('target_id', $id)->delete();
            DB::table('legacy_route_mappings')->where('source_surface', 'public')->where('route_kind', 'public')
                ->where('action', 'redirect')->where('metadata->import', 'articles-v1')
                ->where('target_path', '/articles/'.$entry->slug)->delete();
            DB::table('article_audit_events')->where('entity_type', $entry->getMorphClass())->where('entity_id', $id)->delete();
            $entry->delete();
            DB::table('article_audit_events')->insert([
                'actor_id' => $actor->id, 'entity_type' => $entry->getMorphClass(), 'entity_id' => $id,
                'action' => 'delete', 'before' => null, 'after' => json_encode(['id' => $id, 'revision' => $revision]), 'created_at' => now(),
            ]);
            app(ArticleMediaLifecycle::class)->scheduleUnused($hashes, $actor->id);
        });
    }

    public function saveTaxonomy(string $kind, ?int $id, array $input, ?string $expectedHash = null): Model
    {
        $actor = $this->actor();
        abort_unless($kind === 'author', 404);

        return $this->saveAuthor($actor, $id, $input, $expectedHash);
    }

    public function saveAuthorParameters(int $id, array $patch, string $expectedHash): Model
    {
        $actor = $this->actor();
        if (array_diff(array_keys($patch), ['photo_path', 'avatar_path', 'home_sort_order', 'home_role_text', 'home_cutout_key'])) {
            throw ValidationException::withMessages(['request' => 'Переданы неподдерживаемые параметры автора.']);
        }

        return $this->saveAuthor($actor, $id, $patch, $expectedHash, true);
    }

    private function saveAuthor(User $actor, ?int $id, array $input, ?string $expectedHash, bool $parametersOnly = false): Model
    {
        if (array_key_exists('user_id', $input)) {
            throw ValidationException::withMessages(['user_id' => 'Автор задаётся отдельно от учётной записи.']);
        }

        return app(ArticleMediaGuard::class)->run(function () use ($actor, $id, $input, $expectedHash, $parametersOnly) {
            $actor = $this->actor();
            $class = ContentAuthor::class;
            $record = $id ? $class::query()->lockForUpdate()->findOrFail($id) : new $class;
            if ($parametersOnly) {
                $input = [...$record->only(['name', 'slug', 'bio', 'photo_path', 'avatar_path', 'home_sort_order', 'home_role_text', 'home_cutout_key']), ...$input];
            }
            // Validate the effective placement even when a full form omits a saved home field.
            $input = [...$record->only(['home_sort_order', 'home_role_text', 'home_cutout_key']), ...$input];
            if ($id && hash('sha256', json_encode($record->getAttributes())) !== $expectedHash) {
                throw ValidationException::withMessages(['expectedHash' => 'Запись изменена. Обновите страницу.']);
            }
            foreach (['bio', 'photo_path', 'avatar_path', 'home_sort_order', 'home_role_text', 'home_cutout_key'] as $field) {
                if (array_key_exists($field, $input) && $input[$field] === '') {
                    $input[$field] = null;
                }
            }
            $imageRules = ['nullable', 'regex:~^article-media/[a-zA-Z0-9._/-]+\.(?:jpe?g|png|webp|avif|gif)$~i', 'not_regex:~\.\.~'];
            $data = Validator::make($input, ['name' => ['required', 'string', 'max:255'], 'slug' => ['required', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'max:120'], 'bio' => ['nullable', 'string', 'max:10000'], 'photo_path' => $imageRules, 'avatar_path' => $imageRules,
                'home_sort_order' => ['nullable', 'integer', 'min:1', 'max:65535'], 'home_role_text' => ['required_with:home_sort_order', 'nullable', 'string', 'max:1000'],
                'home_cutout_key' => ['required_with:home_sort_order', 'nullable', 'in:demo-author-6,demo-author-12,demo-author-14,demo-author-11,demo-author-5,demo-author-13,demo-author-3,demo-author-7'],
            ])->validate();
            if (isset($data['home_sort_order'])) {
                $data['home_sort_order'] = (int) $data['home_sort_order'];
            }
            if ($id && $record->slug !== $data['slug']) {
                throw ValidationException::withMessages(['slug' => 'Адрес существующей записи сохраняется.']);
            }
            if ($class::query()->where('slug', $data['slug'])->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) {
                throw ValidationException::withMessages(['slug' => 'Адрес уже используется.']);
            }
            $before = $record->exists ? $record->getAttributes() : null;
            $oldHashes = app(ArticleMediaLifecycle::class)->hashes([$record->photo_path, $record->avatar_path]);
            app(ArticleMedia::class)->assertRegistered($data['photo_path'] ?? null);
            try {
                app(ArticleMedia::class)->assertRegistered($data['avatar_path'] ?? null);
            } catch (ValidationException) {
                throw ValidationException::withMessages(['avatar_path' => 'Медиа не зарегистрировано. Загрузите файл через редактор.']);
            }
            $record->fill($data);
            if ($record->exists && ! $record->isDirty()) {
                return $record;
            }
            $record->save();
            $this->audit($actor, $record, $before);
            ArticleMediaAsset::query()->whereIn('sha256', app(ArticleMediaLifecycle::class)->hashes([$record->photo_path, $record->avatar_path]))->update(['lease_at' => null]);
            app(ArticleMediaLifecycle::class)->scheduleUnused($oldHashes, $actor->id);

            return $record;
        });
    }

    private function audit(User $actor, Model $entry, ?array $before): void
    {
        DB::table('article_audit_events')->insert(['actor_id' => $actor->id, 'entity_type' => $entry->getMorphClass(), 'entity_id' => $entry->id, 'action' => $before ? 'update' : 'create', 'before' => $before ? json_encode($before) : null, 'after' => json_encode($entry->getAttributes()), 'created_at' => now()]);
    }
}
