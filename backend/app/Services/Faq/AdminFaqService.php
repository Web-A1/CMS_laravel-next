<?php

namespace App\Services\Faq;

use App\Models\AdminOperationEvent;
use App\Models\ContentEntry;
use App\Models\ContentPlacement;
use App\Models\User;
use App\Support\ImpersonationContext;
use App\Support\Rbac;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AdminFaqService
{
    public function query(User $actor): Builder
    {
        $this->authorize($actor);

        return ContentEntry::query()->faq()->with('placements');
    }

    public function detail(User $actor, int $id): ContentEntry
    {
        return $this->query($actor)->findOrFail($id);
    }

    public function execute(User $actor, string $operation, array $input, string $key, ?int $id = null, ?int $revision = null): ?ContentEntry
    {
        abort_unless(in_array($operation, ['create', 'update', 'parameters', 'placement', 'publish', 'hide', 'delete'], true), 422);

        return DB::transaction(function () use ($actor, $operation, $input, $key, $id, $revision): ?ContentEntry {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $this->authorize($actor, in_array($operation, ['publish', 'hide'], true) ? 'faq.publish' : 'faq.write');
            Validator::make(['key' => $key], ['key' => ['required', 'string', 'max:128']])->validate();
            $auditKey = 'faq:'.hash('sha256', $actor->id.':'.$key);
            abort_if(AdminOperationEvent::query()->where('idempotency_key', $auditKey)->exists(), 409, 'Операция уже выполнена. Откройте действие заново.');
            if ($operation === 'create') {
                abort_unless($id === null && $revision === null, 422);
                $entry = new ContentEntry(['content_type' => ContentEntry::TYPE_FAQ, 'slug' => 'faq-'.Str::uuid(), 'status' => ContentEntry::STATUS_DRAFT, 'body_format' => 'text']);
            } else {
                $entry = ContentEntry::query()->faq()->lockForUpdate()->findOrFail($id);
                abort_unless($revision !== null && $entry->revision === $revision, 409, 'Вопрос изменился. Откройте действие заново.');
            }

            $allowed = match ($operation) {
                'create', 'update' => ['title', 'body', 'show_on_home', 'sort_order', 'subject_id'],
                'parameters' => ['subject_id', 'show_on_home'],
                'placement' => ['show_on_home'],
                default => [],
            };
            if (array_diff(array_keys($input), $allowed)) {
                throw ValidationException::withMessages(['request' => 'Запрос содержит недопустимые поля.']);
            }
            if ($operation === 'parameters') {
                $data = Validator::make($input, [
                    'subject_id' => ['sometimes', 'nullable', 'integer', Rule::exists('subjects', 'id')->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $entry->subject_id ?? 0))],
                    'show_on_home' => ['sometimes', 'required', 'boolean'],
                ])->validate();
                if (array_key_exists('subject_id', $data)) {
                    $entry->subject_id = filled($data['subject_id']) ? (int) $data['subject_id'] : null;
                }
            } elseif (in_array($operation, ['create', 'update'], true)) {
                foreach (['title', 'body'] as $field) {
                    if (is_string($input[$field] ?? null)) {
                        $input[$field] = trim($input[$field]);
                    }
                }
                $data = Validator::make($input, [
                    'title' => ['required', 'string', 'max:255'],
                    'body' => ['required', 'string', 'max:20000'],
                    'show_on_home' => ['required', 'boolean'],
                    'sort_order' => ['required', 'integer', 'min:0', 'max:2147483647'],
                    'subject_id' => ['sometimes', 'nullable', 'integer', Rule::exists('subjects', 'id')->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $entry->subject_id ?? 0))],
                ])->validate();
                if (array_key_exists('subject_id', $data)) {
                    $data['subject_id'] = $data['subject_id'] === null || $data['subject_id'] === '' ? null : (int) $data['subject_id'];
                }
                $entry->fill(collect($data)->only(['title', 'body', 'sort_order', 'subject_id'])->all());
            } elseif ($operation === 'placement') {
                Validator::make($input, ['show_on_home' => ['required', 'boolean']])->validate();
            } elseif ($operation === 'publish') {
                abort_unless(in_array($entry->status, [ContentEntry::STATUS_DRAFT, ContentEntry::STATUS_HIDDEN], true), 409, 'Опубликовать можно черновик или скрытый вопрос.');
                Validator::make(['title' => trim($entry->title), 'body' => trim($entry->body)], [
                    'title' => ['required', 'string', 'max:255'], 'body' => ['required', 'string', 'max:20000'],
                ])->validate();
                $entry->fill(['status' => ContentEntry::STATUS_PUBLISHED, 'published_at' => now()]);
            } elseif ($operation === 'hide') {
                abort_unless($entry->status === ContentEntry::STATUS_PUBLISHED, 409, 'Скрыть можно только опубликованный вопрос.');
                $entry->status = ContentEntry::STATUS_HIDDEN;
            }

            if (in_array($operation, ['update', 'parameters', 'placement'], true)) {
                $homeChanged = array_key_exists('show_on_home', $input)
                    && (bool) $input['show_on_home'] !== (bool) $entry->placements()->where('placement', ContentPlacement::HOME_FAQ)->value('is_enabled');
                if (! $entry->isDirty() && ! $homeChanged) {
                    return $entry->load('placements');
                }
            }

            $entry->revision = ($entry->revision ?? 0) + 1;
            if ($operation === 'delete') {
                $entry->delete();
            } else {
                $entry->save();
                $home = array_key_exists('show_on_home', $input)
                    ? (bool) $input['show_on_home']
                    : $entry->placements()->where('placement', ContentPlacement::HOME_FAQ)->value('is_enabled');
                foreach ([ContentPlacement::FAQ_INDEX => true, ContentPlacement::HOME_FAQ => (bool) $home] as $placement => $enabled) {
                    $entry->placements()->updateOrCreate(['placement' => $placement], ['is_enabled' => $enabled, 'sort_order' => $entry->sort_order]);
                }
            }
            AdminOperationEvent::query()->create([
                'actor_id' => $actor->id, 'operation' => $operation, 'resource' => 'Faq', 'resource_id' => $entry->id,
                'idempotency_key' => $auditKey, 'payload' => ['revision' => $entry->revision],
            ]);

            return $operation === 'delete' ? null : $entry->load('placements');
        }, 3);
    }

    private function authorize(User $actor, ?string $permission = null): void
    {
        $actor = $actor->fresh();
        abort_unless($actor && ! $actor->blocksAuthenticationForMigration()
            && Rbac::hasPermission($actor, 'admin.access') && Rbac::hasPermission($actor, 'faq.read'), 403);
        if ($permission !== null) {
            abort_unless(Rbac::hasPermission($actor, $permission), 403);
            abort_if(ImpersonationContext::isActive(request()), 403);
        }
    }
}
