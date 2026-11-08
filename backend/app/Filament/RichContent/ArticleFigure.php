<?php

namespace App\Filament\RichContent;

use Tiptap\Core\Node;

class ArticleFigure extends Node
{
    public static $name = 'articleFigure';

    public function parseHTML()
    {
        return [['tag' => 'figure']];
    }

    public function renderHTML($node, $HTMLAttributes = [])
    {
        return ['figure', [], 0];
    }
}
