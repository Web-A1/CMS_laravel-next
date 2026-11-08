<?php

namespace App\Filament\RichContent;

use Tiptap\Nodes\OrderedList;

class ArticleOrderedList extends OrderedList
{
    public static function start(mixed $value): ?int
    {
        if ((! is_string($value) && ! is_int($value)) || ! preg_match('/\A[+-]?[0-9]+\z/D', (string) $value)
            || abs((float) $value) > 9_007_199_254_740_991) {
            return null;
        }

        return (int) $value;
    }

    public function addAttributes()
    {
        return [
            'start' => [
                'parseHTML' => fn ($node) => self::start($node->getAttribute('start')),
                // The serializer treats an integer zero as a content placeholder.
                'renderHTML' => fn ($attributes) => ($start = self::start($attributes->start ?? null)) !== null ? ['start' => (string) $start] : [],
            ],
        ];
    }
}
