<?php

namespace App\Filament\RichContent;

use Tiptap\Core\Node;

class ArticleImagePair extends Node
{
    public static $name = 'articleImagePair';

    public function parseHTML()
    {
        return [['tag' => 'div[data-article-images="pair"]']];
    }

    public function renderHTML($node, $HTMLAttributes = [])
    {
        return ['div', ['data-article-images' => 'pair'], 0];
    }
}
