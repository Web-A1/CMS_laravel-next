<?php

namespace App\Http\Requests;

use App\Services\Learning\LessonAssessmentContentValidator;
use Closure;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpFoundation\Response;

class SaveLessonAssessmentAttemptRequest extends FormRequest
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
            'lastQuestionId' => ['present', 'nullable', 'string', 'max:64', 'regex:/\A[a-z0-9][a-z0-9-]{1,63}\z/'],
            'lockQuestionIds' => [
                'sometimes',
                'array',
                'max:'.LessonAssessmentContentValidator::MAX_QUESTIONS,
            ],
            'lockQuestionIds.*' => [
                'required',
                'string',
                'max:64',
                'regex:/\A[a-z0-9][a-z0-9-]{1,63}\z/',
                'distinct:strict',
            ],
            'responses' => [
                'present',
                'array',
                'max:'.LessonAssessmentContentValidator::MAX_QUESTIONS,
            ],
            'responses.*' => ['required', 'array:questionId,response'],
            'responses.*.questionId' => [
                'required',
                'string',
                'max:64',
                'regex:/\A[a-z0-9][a-z0-9-]{1,63}\z/',
                'distinct:strict',
            ],
            'responses.*.response' => [
                'required',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_array($value) || array_is_list($value)) {
                        $fail("The {$attribute} field must be a JSON object.");
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

            if (
                $keys !== ['lastQuestionId', 'responses', 'revision'] &&
                $keys !== ['lastQuestionId', 'lockQuestionIds', 'responses', 'revision']
            ) {
                $validator->errors()->add('request', 'The request contains unexpected fields.');
            }
        }];
    }

    protected function failedValidation(ValidatorContract $validator): never
    {
        $exception = new ValidationException($validator);

        throw new HttpResponseException(response()
            ->json([
                'message' => $exception->getMessage(),
                'errors' => $exception->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY)
            ->header('Cache-Control', 'private, no-store'));
    }
}
