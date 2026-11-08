<?php

namespace App\Filament\RichContent;

use Tiptap\Core\Extension;

class ArticleTableSemantics extends Extension
{
    public static $name = 'articleTableSemantics';

    public static function scope(mixed $value): ?string
    {
        return in_array($value, ['row', 'col', 'rowgroup', 'colgroup'], true) ? $value : null;
    }

    public function addGlobalAttributes()
    {
        return [[
            'types' => ['tableHeader'],
            'attributes' => [
                'scope' => [
                    'default' => null,
                    'parseHTML' => fn ($node) => self::scope($node->getAttribute('scope')),
                    'renderHTML' => fn ($attributes) => ($scope = self::scope($attributes->scope ?? null)) !== null ? ['scope' => $scope] : [],
                ],
            ],
        ]];
    }
}
