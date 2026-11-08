<?php

namespace App\Services\Articles;

use App\Models\ContentEntry;
use App\Models\ContentPlacement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ArticleRecommendations
{
    private const LIMITS = ['article' => 3, 'faq' => 2];

    public function resolve(ContentEntry $entry): array
    {
        $result = [];
        foreach (self::LIMITS as $type => $limit) {
            $pins = $entry->payload['recommendations'][$type.'_ids'] ?? [];
            $pins = is_array($pins) ? array_slice(array_values(array_filter($pins, fn ($id) => is_int($id) && $id > 0)), 0, $limit) : [];
            $query = $this->eligible($entry, $type)->reorder();
            if ($pins) {
                $cases = implode(' ', array_map(fn ($offset) => 'WHEN content_entries.id = ? THEN '.$offset, array_keys($pins)));
                $query->orderByRaw('CASE '.$cases.' ELSE '.count($pins).' END', $pins);
            }
            $result[$type === 'article' ? 'articles' : 'faqs'] = $this->order($query, $entry, $type)->limit($limit)->get();
        }

        return $result;
    }

    public function eligible(ContentEntry $context, string $type): Builder
    {
        abort_unless(array_key_exists($type, self::LIMITS), 404);
        $placement = $type === 'article' ? ContentPlacement::ARTICLES_INDEX : ContentPlacement::FAQ_INDEX;
        $query = ContentEntry::query()->select('content_entries.*')
            ->selectRaw('content_placements.sort_order as placement_sort_order')
            ->where('content_entries.content_type', $type)->published()
            ->where('content_entries.id', '<>', $context->id ?? 0)
            ->with($type === 'article' ? ['category', 'subject', 'author'] : ['category', 'subject'])
            ->join('content_placements', function ($join) use ($placement): void {
                $join->on('content_placements.content_entry_id', '=', 'content_entries.id')
                    ->where('content_placements.placement', $placement)->where('content_placements.is_enabled', true);
            });
        if ($type === 'faq') {
            $query->where(fn (Builder $query) => $query->whereNull('content_entries.content_category_id')
                ->orWhereHas('category', fn (Builder $category) => $category->faq()->active()));
        }
        if ($context->subject_id !== null) {
            $context->loadMissing('subject');
            $context->subject?->is_active
                ? $query->where('content_entries.subject_id', $context->subject_id)
                : $query->whereRaw('1 = 0');
        } elseif ($type === 'article' && $context->content_author_id !== null) {
            $query->whereNull('content_entries.subject_id')->where('content_entries.content_author_id', $context->content_author_id);
        } else {
            $query->whereRaw('1 = 0');
        }

        return $this->order($query, $context, $type);
    }

    private function order(Builder $query, ContentEntry $entry, string $type): Builder
    {
        if ($type === 'faq') {
            return $query->orderBy('content_placements.sort_order')->orderBy('content_entries.sort_order')->orderBy('content_entries.id');
        }
        if ($entry->content_author_id !== null) {
            $query->orderByRaw('CASE WHEN content_entries.content_author_id = ? THEN 0 ELSE 1 END', [$entry->content_author_id]);
        }

        return $query->orderByDesc('content_entries.published_at')->orderByDesc('content_entries.id');
    }

    public function payload(ContentEntry $entry, array $input, array $validated): ?array
    {
        Validator::make($input, ['payload' => ['prohibited']])->validate();
        $old = $entry->payload['recommendations'] ?? [];
        if (! is_array($old)) {
            throw ValidationException::withMessages(['recommendation_article_ids' => 'Недопустимая структура рекомендаций.']);
        }
        $context = clone $entry;
        $context->fill(array_intersect_key($validated, array_flip(['subject_id', 'content_author_id'])));
        $context->unsetRelation('subject');
        $recommendations = [];
        foreach (self::LIMITS as $type => $limit) {
            $field = 'recommendation_'.$type.'_ids';
            $previous = $old[$type.'_ids'] ?? [];
            $ids = array_key_exists($field, $input) ? $input[$field] : $previous;
            Validator::make([$field => $ids], [$field => ['present', 'array', 'list', 'max:'.$limit], $field.'.*' => [
                'required', 'integer', 'min:1',
                function ($attribute, $value, $fail): void {
                    if (! is_int($value) && (! is_string($value) || ! preg_match('/^[1-9][0-9]*$/D', $value))) {
                        $fail('Выберите корректный положительный ID.');
                    }
                },
            ]])->validate();
            $ids = array_map(fn ($id) => (int) $id, $ids);
            if (count(array_unique($ids)) !== count($ids) || ($entry->id && in_array($entry->id, $ids, true))) {
                throw ValidationException::withMessages([$field => 'Повторы и текущая статья недопустимы.']);
            }
            $new = array_values(array_diff($ids, is_array($previous) ? $previous : []));
            if ($new) {
                $available = $this->eligible($context, $type)->withoutEagerLoads()->whereIn('content_entries.id', $new)->pluck('content_entries.id')->all();
                if (array_diff($new, $available)) {
                    throw ValidationException::withMessages([$field => 'Новая рекомендация недоступна для текущего предмета и автора.']);
                }
            }
            $recommendations[$type.'_ids'] = $ids;
        }

        if (! array_key_exists('recommendations', $entry->payload ?? []) && ! array_key_exists('recommendation_article_ids', $input) && ! array_key_exists('recommendation_faq_ids', $input)) {
            return $entry->payload;
        }

        return [...($entry->payload ?? []), 'recommendations' => $recommendations];
    }

    public function options(ContentEntry $context, string $type, string $search): array
    {
        app(ArticleEditor::class)->actor();

        return $this->eligible($context, $type)->withoutEagerLoads()
            ->where('content_entries.title', 'like', '%'.mb_substr($search, 0, 120).'%')->limit(50)->pluck('content_entries.title', 'content_entries.id')->all();
    }

    public function labels(ContentEntry $context, string $type, array $ids): array
    {
        app(ArticleEditor::class)->actor();
        $ids = array_slice(array_values(array_filter($ids, fn ($id) => filter_var($id, FILTER_VALIDATE_INT) && $id > 0)), 0, self::LIMITS[$type]);
        $titles = ContentEntry::query()->whereIn('id', $ids)->pluck('title', 'id');
        $available = $this->eligible($context, $type)->withoutEagerLoads()->whereIn('content_entries.id', $ids)->pluck('content_entries.id')->all();
        $labels = [];
        foreach ($ids as $id) {
            $labels[$id] = (in_array((int) $id, $available, true) ? '' : '[Недоступно] ').($titles[$id] ?? '#'.$id);
        }

        return $labels;
    }
}
