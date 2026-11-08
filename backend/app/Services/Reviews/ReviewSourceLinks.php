<?php

namespace App\Services\Reviews;

use App\Models\Review;
use App\Models\ReviewSource;
use Illuminate\Support\Str;

final class ReviewSourceLinks
{
    public static function isPublicUrl(mixed $url): bool
    {
        if (! is_string($url) || ! Str::isUrl($url, ['http', 'https'])) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts) && ! isset($parts['user']) && ! isset($parts['pass'])
            && ! preg_match('/[\x00-\x20\x7f]/', $url);
    }

    public function links(Review $review): array
    {
        if (! $review->relationLoaded('sources')) {
            return [];
        }
        $sources = $review->sources->sortBy('id');
        $override = $sources->first(fn (ReviewSource $source): bool => $source->source_type === ReviewSource::TYPE_MANUAL
            && $source->source_ref === $this->reference($review));
        if ($override) {
            return $override->is_public && self::isPublicUrl($override->source_url)
                ? [$this->link($this->type($override->source_url), $override->source_url)] : [];
        }

        return $sources->filter(fn (ReviewSource $source): bool => $source->is_public
            && in_array($source->source_type, [ReviewSource::TYPE_VK_WEBARCHIVE, ReviewSource::TYPE_YANDEX_MAPS_WEBARCHIVE], true)
            && self::isPublicUrl($source->source_url))
            ->unique(fn (ReviewSource $source): string => $source->source_type.'|'.$source->source_url)
            ->map(fn (ReviewSource $source): array => $this->link($source->source_type, $source->source_url))->values()->all();
    }

    public function url(Review $review): ?string
    {
        $review->loadMissing('sources');

        return $this->links($review)[0]['url'] ?? null;
    }

    public function sync(Review $review, ?string $url): void
    {
        $url = filled($url) ? trim($url) : null;
        if ($url === $this->url($review)) {
            return;
        }
        // Keep imported provenance and evidence intact, including when the public link is cleared.
        $review->sources()->updateOrCreate([
            'source_type' => ReviewSource::TYPE_MANUAL, 'source_ref' => $this->reference($review),
        ], ['source_url' => $url, 'is_public' => $url !== null]);
        $review->unsetRelation('sources');
    }

    private function reference(Review $review): string
    {
        return 'admin-public-link:'.$review->id;
    }

    private function type(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (in_array($host, ['vk.com', 'www.vk.com', 'm.vk.com', 'vk.ru', 'www.vk.ru', 'm.vk.ru'], true)) {
            return ReviewSource::TYPE_VK_WEBARCHIVE;
        }
        if (in_array($host, ['yandex.ru', 'www.yandex.ru', 'maps.yandex.ru', 'yandex.com', 'www.yandex.com', 'maps.yandex.com', 'yandex.by', 'yandex.kz', 'yandex.uz'], true)) {
            return ReviewSource::TYPE_YANDEX_MAPS_WEBARCHIVE;
        }

        return ReviewSource::TYPE_MANUAL;
    }

    private function link(string $type, string $url): array
    {
        return ['type' => $type, 'url' => $url, 'label' => match ($type) {
            ReviewSource::TYPE_VK_WEBARCHIVE => 'Смотреть отзыв ВКонтакте',
            ReviewSource::TYPE_YANDEX_MAPS_WEBARCHIVE => 'Смотреть отзыв на Яндекс.Картах',
            default => 'Источник',
        }];
    }
}
