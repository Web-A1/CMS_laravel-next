<?php

namespace App\Rules;

use App\Models\ReviewImageAsset;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Storage;

final class ReviewAuthorPhotoPath implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/\Areview-authors\/[a-zA-Z0-9_-]+\.(?:jpe?g|png|webp|avif)\z/', $value)) {
            $fail('Выберите фотографию автора из загруженных изображений.');

            return;
        }

        $asset = ReviewImageAsset::where('primary_path', $value)->first();
        if ($asset) {
            if ($asset->kind !== 'avatar' || $asset->status !== 'ready') {
                $fail($asset->error ?? 'Фотография ещё готовится. Дождитесь завершения и сохраните отзыв.');
            }

            return;
        }
        $disk = Storage::disk('public');
        if (! $disk->exists($value) || $disk->size($value) > 2 * 1024 * 1024) {
            $fail('Фотография должна быть изображением JPEG, PNG, WebP или AVIF размером до 2 МБ.');

            return;
        }
        $image = @getimagesizefromstring($disk->get($value));
        if ($image === false || ! in_array($image['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/avif'], true)) {
            $fail('Фотография должна быть изображением JPEG, PNG, WebP или AVIF размером до 2 МБ.');
        }
    }
}
