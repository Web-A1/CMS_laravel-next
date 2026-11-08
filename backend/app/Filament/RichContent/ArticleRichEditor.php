<?php

namespace App\Filament\RichContent;

use Filament\Forms\Components\RichEditor;
use Tiptap\Editor;

class ArticleRichEditor extends RichEditor
{
    public function getTipTapEditor(): Editor
    {
        return ArticleContentRenderer::make()
            ->plugins($this->getPlugins())
            ->linkProtocols($this->getLinkProtocols())
            ->getEditor();
    }
}
