<?php

namespace App\Rules;

use App\Models\ReviewImageAsset;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Storage;

final class ReviewScreenshotPath implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Only the service can resolve these against the locked review's current public list.
        if (is_string($value) && preg_match('/\Aexisting:review_(?:media|evidence):[1-9][0-9]*\z/', $value)) {
            return;
        }
        if (! is_string($value) || ! preg_match('/\Areview-screenshots\/[a-zA-Z0-9_-]+\.(?:jpe?g|png|webp|avif)\z/', $value)) {
            $fail('Выберите скриншот из загруженных изображений.');

            return;
        }

        $asset = ReviewImageAsset::where('primary_path', $value)->first();
        if ($asset) {
            if ($asset->kind !== 'screenshot' || $asset->status !== 'ready') {
                $fail($asset->error ?? 'Скриншот ещё готовится. Дождитесь завершения и сохраните отзыв.');
            }

            return;
        }
        $disk = Storage::disk('public');
        if (! $disk->exists($value) || $disk->size($value) > 5 * 1024 * 1024) {
            $fail('Скриншот должен быть изображением JPEG, PNG, WebP или AVIF размером до 5 МБ.');

            return;
        }
        $image = @getimagesizefromstring($disk->get($value));
        if ($image === false || ! in_array($image['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/avif'], true)) {
            $fail('Скриншот должен быть изображением JPEG, PNG, WebP или AVIF размером до 5 МБ.');
        }
    }
}
