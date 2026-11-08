<?php

namespace App\Filament\RichContent;

use Filament\Forms\Components\RichEditor\RichContentRenderer;
use Tiptap\Nodes\OrderedList;

class ArticleContentRenderer extends RichContentRenderer
{
    public function getTipTapPhpExtensions(): array
    {
        // Replace rather than append: duplicate nodes emit duplicate closing tags.
        return array_map(fn ($extension) => $extension::class === OrderedList::class ? new ArticleOrderedList : $extension, parent::getTipTapPhpExtensions());
    }
}
