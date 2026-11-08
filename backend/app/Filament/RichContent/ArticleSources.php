<?php

namespace App\Filament\RichContent;

use Tiptap\Core\Extension;

class ArticleSources extends Extension
{
    public static $name = 'articleSources';

    public function addGlobalAttributes()
    {
        return [[
            'types' => ['heading'],
            'attributes' => [
                'articleSources' => [
                    'default' => false,
                    'parseHTML' => fn ($node) => $node->hasAttribute('data-article-sources'),
                    'renderHTML' => fn ($attributes) => ($attributes->articleSources ?? false) ? ['data-article-sources' => ''] : [],
                ],
            ],
        ]];
    }
}
