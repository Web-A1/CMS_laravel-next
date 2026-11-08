<?php

namespace App\Rules;

use App\Services\Reviews\ReviewSourceLinks;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class ReviewSourceUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! ReviewSourceLinks::isPublicUrl($value)) {
            $fail('Укажите полную ссылку на источник, начинающуюся с http:// или https://, без логина и пароля.');
        }
    }
}
