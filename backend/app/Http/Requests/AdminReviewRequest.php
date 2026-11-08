<?php

namespace App\Http\Requests;

use App\Rules\ReviewAuthorPhotoPath;
use App\Rules\ReviewScreenshotPath;
use App\Rules\ReviewSourceUrl;
use Closure;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdminReviewRequest extends FormRequest
{
    public const PARAMETERS = ['isAnonymous', 'reviewWrittenAt', 'authorPhotoPath', 'screenshotPaths', 'scope', 'subjectId', 'courseId', 'showOnHome'];

    public static function parameterRules(): array
    {
        $rules = array_intersect_key(self::inputRules(), array_flip([...self::PARAMETERS, 'screenshotPaths.*']));
        foreach (self::PARAMETERS as $field) {
            $rules[$field] = ['sometimes', ...array_filter($rules[$field], fn ($rule) => ! in_array($rule, ['required', 'present', 'sometimes'], true))];
        }

        return $rules + ['revision' => ['required', 'integer:strict', 'min:0']];
    }

    public function authorize(): bool
    {
        return true;
    }

    public static function inputRules(): array
    {
        return [
            'displayName' => ['present', 'nullable', 'string', 'max:255', 'required_if:isAnonymous,false'],
            'authorPhotoPath' => ['sometimes', 'nullable', 'string', 'max:255', new ReviewAuthorPhotoPath],
            'screenshotPaths' => ['sometimes', 'array', 'max:10'],
            'screenshotPaths.*' => ['required', 'string', 'max:255', 'distinct', new ReviewScreenshotPath],
            'sourceUrl' => ['sometimes', 'nullable', 'string', 'max:255', new ReviewSourceUrl],
            'showOnHome' => ['sometimes', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_bool($value)) {
                    $fail('Укажите, показывать ли отзыв на главной.');
                }
            }],
            'isAnonymous' => ['required', static function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_bool($value)) {
                    $fail('Укажите, является ли отзыв анонимным.');
                }
            }],
            'text' => ['required', 'string', 'max:20000', 'regex:/\S/u'],
            'reviewWrittenAt' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'scope' => ['required', Rule::in(['all', 'subject', 'course'])],
            'subjectId' => ['present', 'nullable', 'integer', 'min:1', 'exists:subjects,id', 'required_if:scope,subject'],
            'courseId' => ['present', 'nullable', 'integer', 'min:1', 'exists:courses,id', 'required_if:scope,course'],
        ];
    }

    public function rules(): array
    {
        $revision = ['required', static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_int($value) || $value < 0) {
                $fail('Версия должна быть целым неотрицательным числом.');
            }
        }];

        if ($this->isMethod('GET')) {
            return [
                'page' => ['sometimes', 'integer', 'min:1'],
                'perPage' => ['sometimes', 'integer', 'between:1,100'],
                'status' => ['sometimes', Rule::in(['all', 'draft', 'published', 'hidden'])],
                'search' => ['sometimes', 'nullable', 'string', 'max:255'],
                'photo' => ['sometimes', Rule::in(['all', 'yes', 'no'])],
                'subjectId' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:subjects,id'],
                'date' => ['sometimes', Rule::in(['newest', 'oldest', 'this_month'])],
                ...self::addedFilterRules($this->all()),
            ];
        }
        if ($this->routeIs('admin.reviews.publication')) {
            return ['action' => ['required', Rule::in(['publish', 'hide'])], 'revision' => $revision];
        }
        if ($this->isMethod('DELETE')) {
            return ['revision' => $revision, 'confirmation' => ['required', 'string', 'max:20']];
        }

        return self::inputRules() + ($this->isMethod('PATCH') ? ['revision' => $revision] : []);
    }

    public static function validateScope(Validator $validator, array $input, bool $validateName = true): void
    {
        $scope = $input['scope'] ?? null;
        foreach (['subjectId' => 'subject', 'courseId' => 'course'] as $field => $expectedScope) {
            if ($scope !== $expectedScope && ($input[$field] ?? null) !== null) {
                $validator->errors()->add($field, 'Укажите ровно один охват отзыва.');
            }
        }
        $displayName = $input['displayName'] ?? null;
        if ($validateName && ($input['isAnonymous'] ?? null) === false && (! is_string($displayName) || trim($displayName) === '')) {
            $validator->errors()->add('displayName', 'Укажите публичное имя автора.');
        }
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('request', 'Запрос содержит недопустимые поля.');
            }
            if (! $this->isMethod('GET')) {
                if ($this->idempotencyKey() === null) {
                    $validator->errors()->add('Idempotency-Key', 'Нужен непустой Idempotency-Key длиной до 160 символов.');
                }
                if (! $this->routeIs('admin.reviews.publication') && ! $this->isMethod('DELETE')) {
                    self::validateScope($validator, $this->all());
                }
            }
        }];
    }

    public function idempotencyKey(): ?string
    {
        $key = $this->header('Idempotency-Key');

        return is_string($key) && trim($key) !== '' && mb_strlen($key) <= 160 ? $key : null;
    }

    public static function addedFilterRules(array $input): array
    {
        return ['home' => ['sometimes', 'nullable', Rule::in(['all', 'yes', 'no'])],
            'addedOrder' => ['sometimes', 'nullable', Rule::in(['newest', 'oldest'])],
            'addedFrom' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'addedTo' => ['sometimes', 'nullable', 'date_format:Y-m-d', ...(filled($input['addedFrom'] ?? null) ? ['after_or_equal:addedFrom'] : [])]];
    }

    protected function failedValidation(ValidatorContract $validator): never
    {
        throw new HttpResponseException(response()->json([
            'error' => ['code' => 'ADMIN_REVIEW_VALIDATION_FAILED', 'message' => 'Проверьте поля отзыва.'],
            'errors' => $validator->errors()->toArray(),
        ], 422)->header('Cache-Control', 'private, no-store'));
    }
}
