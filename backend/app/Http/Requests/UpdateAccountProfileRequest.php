<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAccountProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255']];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Укажите имя.',
            'name.string' => 'Имя должно быть строкой.',
            'name.max' => 'Имя не должно быть длиннее 255 символов.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_keys($this->all()) !== ['name']) {
                $validator->errors()->add('request', 'Можно изменить только имя.');
            }
        }];
    }
}
