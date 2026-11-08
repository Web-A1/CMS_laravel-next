<?php

namespace App\Filament\RichContent;

use Tiptap\Core\Node;

class ArticleVideo extends Node
{
    public static $name = 'articleVideo';

    public function addAttributes()
    {
        return ['src' => [], 'title' => ['default' => 'Видео к статье']];
    }

    public function parseHTML()
    {
        return [['tag' => 'iframe[src]']];
    }

    public function renderHTML($node, $HTMLAttributes = [])
    {
        return ['iframe', $HTMLAttributes];
    }
}
