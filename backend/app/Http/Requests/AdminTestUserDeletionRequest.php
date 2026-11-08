<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AdminTestUserDeletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route permission and transactional subject checks are separate.
    }

    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('request', 'Запрос содержит недопустимые поля.');
            }
        }];
    }
    public static function validateData(array $data): array
    {
        $validator = \Illuminate\Support\Facades\Validator::make($data, ['idempotency_key' => ['required', 'uuid']]);
        $validator->after(function (Validator $validator) use ($data): void {
            if (array_diff(array_keys($data), ['idempotency_key']) !== []) {
                $validator->errors()->add('request', 'Запрос содержит недопустимые поля.');
            }
        });

        return $validator->validate();
    }
}
