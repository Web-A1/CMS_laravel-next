<?php

namespace App\Http\Requests;

use App\Models\UserProfile;
use App\Services\AdminStaffProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdminUserMutationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route permissions and transactional subject scope are checked separately.
    }

    public function rules(): array
    {
        return self::rulesFor($this->route()->getActionMethod());
    }

    public static function rulesFor(string $operation): array
    {
        $revision = ['revision' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/']];

        return match ($operation) {
            'profileParameters' => $revision + array_intersect_key(self::rulesFor('updateProfile'), array_flip(['birth_date', 'gender'])),
            'updateProfile' => $revision + [
                'name' => ['sometimes', 'required', 'string', 'max:255'],
                'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
                'gender' => ['nullable', Rule::in(['female', 'male', 'other'])],
                'school' => ['nullable', 'string', 'max:255'],
                'study_year' => ['nullable', 'string', 'max:80'],
                'enrollment_year' => ['nullable', 'integer', 'between:1900,'.(now()->year + 1)],
                'study_direction' => ['nullable', 'string', 'max:255'],
                'profession' => ['nullable', 'string', 'max:255'],
                'interests' => ['nullable', 'string', 'max:2000'],
                'referral_source' => ['nullable', 'string', 'max:255'],
                'preferred_contact' => ['nullable', 'string', 'max:80'],
                'contact_details' => ['nullable', 'string', 'max:255'],
                'vk_profile' => ['nullable', 'string', 'max:255'],
            ] + AdminStaffProfile::nameRules(),
            'updateManager' => $revision + ['manager_id' => ['present', 'nullable', 'integer', 'min:1']],
            'updateTags' => $revision + [
                'tags' => ['present', 'array', 'max:20'],
                'tags.*' => ['required', 'string', 'max:80', 'distinct'],
            ],
            'comment' => [
                'comment' => ['required', 'string', 'max:5000'],
                'idempotency_key' => ['required', 'uuid'],
            ],
            default => [],
        };
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = array_filter(array_keys($this->rules()), fn ($key) => ! str_contains($key, '.'));
            if (array_diff(array_keys($this->all()), $allowed) !== []) {
                $validator->errors()->add('request', 'Запрос содержит недопустимые поля.');
            }
            if ($this->route()->getActionMethod() === 'updateProfile'
                && array_intersect(array_keys($this->all()), ['name', 'first_name', 'last_name', ...UserProfile::FIELDS]) === []) {
                $validator->errors()->add('request', 'Укажите поля профиля.');
            }
        }];
    }

    public static function validateFor(string $operation, array $data): array
    {
        // Livewire bypasses the API's TrimStrings / ConvertEmptyStringsToNull middleware.
        $data = array_map(fn ($value) => is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value, $data);
        $validator = \Illuminate\Support\Facades\Validator::make($data, self::rulesFor($operation));
        $validator->after(function (Validator $validator) use ($operation, $data): void {
            $allowed = array_filter(array_keys(self::rulesFor($operation)), fn ($key) => ! str_contains($key, '.'));
            if (array_diff(array_keys($data), $allowed) !== []) {
                $validator->errors()->add('request', 'Запрос содержит недопустимые поля.');
            }
            if (in_array($operation, ['updateProfile', 'profileParameters'], true) && array_intersect(array_keys($data), ['name', 'first_name', 'last_name', ...UserProfile::FIELDS]) === []) {
                $validator->errors()->add('request', 'Укажите поля профиля.');
            }
        });

        return $validator->validate();
    }
}
