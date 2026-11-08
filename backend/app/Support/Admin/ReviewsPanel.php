<?php

namespace App\Support\Admin;

use App\Http\Requests\AdminReviewRequest;
use App\Http\Resources\AdminReviewResource;
use App\Models\Course;
use App\Models\Subject;
use App\Models\User;
use App\Services\Reviews\AdminReviewDeletion;
use App\Services\Reviews\AdminReviewService;
use App\Support\ImpersonationContext;
use App\Support\Rbac;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ReviewsPanel
{
    public const PUBLICATION_LABELS = ['draft' => 'Черновик', 'published' => 'Опубликован', 'hidden' => 'Скрыт', 'archived' => 'В архиве'];

    public const ORIGIN_LABELS = ['manual' => 'Добавлен вручную', 'platform' => 'С сайта', 'legacy_import' => 'Импортирован', 'dev_seed' => 'Тестовый'];

    public const EVENT_LABELS = ['admin_create' => 'Создан черновик', 'admin_update' => 'Отзыв изменён', 'admin_parameters' => 'Отзыв изменён', 'admin_publish' => 'Опубликован', 'admin_hide' => 'Скрыт с сайта', 'admin_placement' => 'Изменён показ на главной'];

    public function actor(string $permission = 'reviews.read'): User
    {
        $actor = auth('web')->user();
        $actor = $actor instanceof User ? $actor->fresh() : null;
        abort_unless($actor instanceof User && ! $actor->blocksAuthenticationForMigration()
            && Rbac::hasPermission($actor, 'admin.access') && Rbac::hasPermission($actor, $permission), 403);
        if ($permission !== 'reviews.read') {
            abort_if(ImpersonationContext::isActive(request()), 403);
        }

        return $actor;
    }

    public function query(array $filters, ?string $sortColumn = null, ?string $sortDirection = null): Builder
    {
        $this->actor();

        Validator::make($filters, [
            'reviewId' => ['nullable', 'integer', 'min:1', 'max:'.PHP_INT_MAX],
            'author' => ['nullable', 'string', 'max:200'], 'text' => ['nullable', 'string', 'max:200'],
            'authorPhoto' => ['nullable', Rule::in(['all', 'yes', 'no'])],
            'courseId' => ['nullable', 'integer'], 'scope' => ['nullable', Rule::in(['general', 'subject', 'course'])],
            'writtenFrom' => ['nullable', 'date_format:Y-m-d'],
            'writtenTo' => ['nullable', 'date_format:Y-m-d', ...(filled($filters['writtenFrom'] ?? null) ? ['after_or_equal:writtenFrom'] : [])],
        ], [
            'reviewId.integer' => 'Введите целый положительный номер отзыва.',
            'reviewId.min' => 'Номер отзыва должен быть больше нуля.',
            'reviewId.max' => 'Номер отзыва слишком большой.',
            'writtenFrom.date_format' => 'Укажите начальную дату в формате ДД.ММ.ГГГГ.',
            'writtenTo.date_format' => 'Укажите конечную дату в формате ДД.ММ.ГГГГ.',
            'writtenTo.after_or_equal' => 'Конечная дата должна быть не раньше начальной.',
        ])->validate();
        $query = app(AdminReviewService::class)->filteredQuery($filters);
        if (filled($filters['reviewId'] ?? null)) {
            $query->whereKey($filters['reviewId']);
        }
        if (($filters['authorPhoto'] ?? 'all') === 'yes') {
            $query->whereNotNull('author_photo_path')->where('author_photo_path', '<>', '');
        } elseif (($filters['authorPhoto'] ?? 'all') === 'no') {
            $query->where(fn ($query) => $query->whereNull('author_photo_path')->orWhere('author_photo_path', ''));
        }
        foreach (['author' => 'display_name', 'text' => 'text'] as $key => $field) {
            if (($value = trim($filters[$key] ?? '')) !== '') {
                $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%';
                $operator = $query->getConnection()->getDriverName() === 'pgsql' ? 'ILIKE' : 'LIKE';
                if ($key === 'author') {
                    $query->where(function (Builder $query) use ($pattern, $value, $operator): void {
                        $query->where(fn (Builder $query) => $query->where('is_anonymous', false)->whereRaw("display_name {$operator} ? ESCAPE '!'", [$pattern]));
                        if (mb_stripos('Анонимный автор', $value) !== false) {
                            $query->orWhere('is_anonymous', true);
                        }
                    });
                } else {
                    $query->whereRaw("{$field} {$operator} ? ESCAPE '!'", [$pattern]);
                }
            }
        }
        if (filled($filters['courseId'] ?? null)) {
            $query->where('course_id', $filters['courseId']);
        }
        match ($filters['scope'] ?? null) {
            'general' => $query->whereNull('subject_id')->whereNull('course_id'),
            'subject' => $query->whereNotNull('subject_id')->whereNull('course_id'),
            'course' => $query->whereNotNull('course_id'),
            default => null,
        };
        if (($filters['date'] ?? null) === 'undated') {
            $query->whereNull('review_written_at');
        }
        if (filled($filters['writtenFrom'] ?? null)) {
            $query->whereDate('review_written_at', '>=', $filters['writtenFrom']);
        }
        if (filled($filters['writtenTo'] ?? null)) {
            $query->whereDate('review_written_at', '<=', $filters['writtenTo']);
        }
        if (! in_array($sortColumn, ['id', 'display_name', 'scope', 'review_written_at', 'created_at'], true)) {
            return $query;
        }
        $direction = $sortDirection === 'asc' ? 'asc' : 'desc';
        $query->reorder();
        if ($sortColumn === 'display_name') {
            $query->orderByRaw("CASE WHEN is_anonymous THEN ? ELSE display_name END {$direction}", ['Анонимный автор']);
        } elseif ($sortColumn === 'scope') {
            $query->orderByRaw("COALESCE((SELECT COALESCE(subjects.title, '') || ' · ' || courses.title FROM courses LEFT JOIN subjects ON subjects.id = courses.subject_id WHERE courses.id = reviews.course_id), (SELECT title FROM subjects WHERE subjects.id = reviews.subject_id), ?) {$direction}", ['Общий отзыв']);
        } else {
            if ($sortColumn === 'review_written_at') {
                $query->orderByRaw('CASE WHEN review_written_at IS NULL THEN 1 ELSE 0 END');
            }
            $query->orderBy($sortColumn, $direction);
        }

        return $sortColumn === 'id' ? $query : $query->orderByDesc('id');
    }

    public function detail(int $id): array
    {
        $this->actor();

        return (new AdminReviewResource(app(AdminReviewService::class)->detail($id)))->resolve();
    }

    public function subjects(): array
    {
        $this->actor();

        return Subject::query()->orderBy('title')->pluck('title', 'id')->all();
    }

    public function courses(): array
    {
        $this->actor();

        return Course::query()->orderBy('title')->pluck('title', 'id')->all();
    }

    public function execute(string $operation, ?int $id, array $input, string $key): array
    {
        abort_unless(in_array($operation, ['create', 'update', 'parameters', 'publication', 'placement'], true), 404);
        $actor = $this->actor($operation === 'publication' ? 'reviews.publish' : 'reviews.write');
        $limit = in_array($operation, ['update', 'parameters'], true) ? 60 : 30;
        $rateKey = 'filament:reviews:'.$operation.':'.$actor->id;
        abort_if(RateLimiter::tooManyAttempts($rateKey, $limit), 429);
        RateLimiter::hit($rateKey, 60);
        $revision = ['required', function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_int($value) || $value < 0) {
                $fail('Версия должна быть целым неотрицательным числом.');
            }
        }];
        $rules = match ($operation) {
            'publication' => ['action' => ['required', Rule::in(['publish', 'hide'])], 'revision' => $revision],
            'placement' => ['showOnHome' => ['required', 'boolean:strict'], 'revision' => $revision],
            'parameters' => AdminReviewRequest::parameterRules(),
            default => AdminReviewRequest::inputRules() + ($operation === 'update' ? ['revision' => $revision] : []),
        };
        $validator = Validator::make($input, $rules);
        $validator->after(function ($validator) use ($input, $rules, $operation, $key): void {
            if (array_diff(array_keys($input), array_keys($rules)) !== []) {
                $validator->errors()->add('request', 'Запрос содержит недопустимые поля.');
            }
            if (trim($key) === '' || mb_strlen($key) > 160) {
                $validator->errors()->add('Idempotency-Key', 'Нужен непустой Idempotency-Key длиной до 160 символов.');
            }
            if (in_array($operation, ['create', 'update'], true)) {
                AdminReviewRequest::validateScope($validator, $input);
            }
        });
        $validated = $validator->validate();
        $review = app(AdminReviewService::class)->execute($actor, $operation, $id, $validated, $key);

        return (new AdminReviewResource($review))->resolve();
    }

    public function delete(int $id, array $input, string $key): array
    {
        $actor = $this->actor('reviews.write');
        $rateKey = 'filament:reviews:delete:'.$actor->id;
        abort_if(RateLimiter::tooManyAttempts($rateKey, 30), 429);
        RateLimiter::hit($rateKey, 60);
        Validator::make($input, ['revision' => ['required', 'integer', 'min:0'], 'confirmation' => ['required', 'string', 'in:'.$id]])->validate();
        abort_if(trim($key) === '' || mb_strlen($key) > 160, 422);

        return app(AdminReviewDeletion::class)->delete($actor, $id, $input, $key);
    }

    public function deleteMany(array $reviews, string $confirmation, string $key): array
    {
        $actor = $this->actor('reviews.write');
        $rateKey = 'filament:reviews:delete:'.$actor->id;
        abort_if(RateLimiter::tooManyAttempts($rateKey, 30), 429);
        RateLimiter::hit($rateKey, 60);

        return app(AdminReviewDeletion::class)->deleteMany($actor, $reviews, $confirmation, $key);
    }

    public function placeMany(array $reviews, bool $showOnHome, string $key): array
    {
        $actor = $this->actor('reviews.write');
        $rateKey = 'filament:reviews:placement:'.$actor->id;
        abort_if(RateLimiter::tooManyAttempts($rateKey, 30), 429);
        RateLimiter::hit($rateKey, 60);

        return app(AdminReviewService::class)->placeMany($actor, $reviews, $showOnHome, $key);
    }
}
