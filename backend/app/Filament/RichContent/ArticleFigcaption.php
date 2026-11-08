<?php

namespace App\Filament\RichContent;

use Tiptap\Core\Node;

class ArticleFigcaption extends Node
{
    public static $name = 'articleFigcaption';

    public function parseHTML()
    {
        return [['tag' => 'figcaption']];
    }

    public function renderHTML($node, $HTMLAttributes = [])
    {
        return ['figcaption', [], 0];
    }
}
