<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpFoundation\Response;

class UpdateLessonAssessmentManualReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('comment')) && trim($this->input('comment')) === '') {
            $this->merge(['comment' => null]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::payloadRules();
    }

    public static function payloadRules(): array
    {
        $integerBps = static function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_int($value) || $value < 0 || $value > 10000) {
                $fail("The {$attribute} field must be an integer between 0 and 10000.");
            }
        };

        return [
            'revision' => [
                'required',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_int($value) || $value < 0) {
                        $fail("The {$attribute} field must be a non-negative integer.");
                    }
                },
            ],
            'scoreBps' => ['required', $integerBps],
            'comment' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $keys = array_keys($this->all());
            sort($keys, SORT_STRING);
            if (! in_array($keys, [
                ['revision', 'scoreBps'],
                ['comment', 'revision', 'scoreBps'],
            ], true)) {
                $validator->errors()->add('request', 'The request contains unexpected fields.');
            }
            if ($this->idempotencyKey() === null) {
                $validator->errors()->add('Idempotency-Key', 'A nonblank Idempotency-Key header of at most 160 characters is required.');
            }
        }];
    }

    public function idempotencyKey(): ?string
    {
        $key = $this->header('Idempotency-Key');

        return is_string($key) && trim($key) !== '' && mb_strlen($key, 'UTF-8') <= 160 ? $key : null;
    }

    protected function failedValidation(ValidatorContract $validator): never
    {
        $exception = new ValidationException($validator);

        throw new HttpResponseException(response()->json([
            'message' => $exception->getMessage(),
            'errors' => $exception->errors(),
        ], Response::HTTP_UNPROCESSABLE_ENTITY)->header('Cache-Control', 'private, no-store'));
    }
}
