<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpFoundation\Response;

class SuggestLessonAssessmentAnswerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'questionId' => ['required', 'string', 'max:64', 'regex:/\A[a-z0-9][a-z0-9-]{1,63}\z/'],
            'partId' => ['required', 'string', 'max:64', 'regex:/\A[a-z0-9][a-z0-9-]{1,63}\z/'],
            'prefix' => ['required', 'string', 'min:3', 'max:500'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $keys = array_keys($this->all());
            sort($keys, SORT_STRING);
            if ($keys !== ['partId', 'prefix', 'questionId']) {
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
