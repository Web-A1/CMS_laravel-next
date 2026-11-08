<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpFoundation\Response;

class SubmitLessonAssessmentAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'revision' => [
                'required',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_int($value) || $value < 0) {
                        $fail("The {$attribute} field must be a non-negative integer.");
                    }
                },
            ],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $keys = array_keys($this->all());
            sort($keys, SORT_STRING);
            if ($keys !== ['revision']) {
                $validator->errors()->add('request', 'The request contains unexpected fields.');
            }
        }];
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
