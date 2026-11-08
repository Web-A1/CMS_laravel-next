<?php

namespace App\Filament\RichContent;

use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\EditorCommand;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Filament\Forms\Components\TextInput;

class ArticleRichContentPlugin implements RichContentPlugin
{
    public function getTipTapPhpExtensions(): array
    {
        return [new ArticleVideo, new ArticleFigure, new ArticleFigcaption, new ArticleImagePair, new ArticleSources, new ArticleTableSemantics];
    }

    public function getTipTapJsExtensions(): array
    {
        return ['/article-editor/video.js', '/article-editor/figure.js', '/article-editor/sources.js', '/article-editor/table-semantics.js'];
    }

    public function getEditorTools(): array
    {
        return [
            RichEditorTool::make('attachFiles')->label('Одно изображение')->icon('heroicon-o-photo')->activeKey('image')
                ->action('articleImage', arguments: '{ ...($getEditor()?.getAttributes(\'image\') ?? {}) }'),
            RichEditorTool::make('articleImagePair')->label('Два изображения рядом')->icon('heroicon-o-view-columns')->action(),
            RichEditorTool::make('articleVideo')->label('Видео Kinescope')->icon('heroicon-o-video-camera')
                ->action(arguments: '{ ...($getEditor()?.getAttributes(\'articleVideo\') ?? {}), editing: $getEditor()?.isActive(\'articleVideo\') }'),
            RichEditorTool::make('articleCaption')->label('Подпись изображения')->icon('heroicon-o-chat-bubble-bottom-center-text')
                ->activeJsExpression('$getEditor()?.isActive(\'image\') || $getEditor()?.isActive(\'articleFigure\')')->disabledWhenNotActive()->activeStyling(false)
                ->action(arguments: <<<'JS'
                    (() => {
                        const selection = $getEditor()?.state.selection;
                        let figure = selection?.node?.type.name === 'articleFigure' ? selection.node : null;
                        if (selection && !figure) {
                            for (let depth = selection.$from.depth; depth > 0; depth--) {
                                if (selection.$from.node(depth).type.name === 'articleFigure') {
                                    figure = selection.$from.node(depth);
                                    break;
                                }
                            }
                        }
                        const caption = figure?.lastChild;
                        return { caption: caption?.type.name === 'articleFigcaption' ? caption.textContent : '' };
                    })()
                    JS),
        ];
    }

    public function getEditorActions(): array
    {
        return [
            ArticleImageActions::make(pair: false),
            ArticleImageActions::make(pair: true),
            Action::make('articleVideo')->label('Видео Kinescope')->modalHeading('Видео Kinescope')
                ->fillForm(fn (array $arguments) => ['src' => $arguments['src'] ?? '', 'title' => $arguments['title'] ?? 'Видео к статье'])
                ->schema([
                    TextInput::make('src')->label('Адрес встроенного видео')->required()->maxLength(255)->rules(['regex:~\Ahttps://kinescope\.io/embed/[A-Za-z0-9]+\z~D']),
                    TextInput::make('title')->label('Название видео')->required()->maxLength(255),
                ])
                ->action(function (array $arguments, array $data, RichEditor $component): void {
                    $component->runCommands([EditorCommand::make(($arguments['editing'] ?? false) ? 'updateAttributes' : 'insertContent', arguments: ($arguments['editing'] ?? false) ? ['articleVideo', $data] : [['type' => 'articleVideo', 'attrs' => $data]])], editorSelection: $arguments['editorSelection']);
                }),
            Action::make('articleCaption')->label('Подпись изображения')->modalHeading('Подпись изображения')
                ->fillForm(fn (array $arguments) => ['caption' => $arguments['caption'] ?? ''])
                ->schema([TextInput::make('caption')->label('Подпись')->maxLength(2000)->helperText('Выберите изображение. Пустая подпись удаляет подпись.')])
                ->action(function (array $arguments, array $data, RichEditor $component): void {
                    $component->runCommands([EditorCommand::make('setArticleCaption', arguments: [$data['caption'] ?? ''])], editorSelection: $arguments['editorSelection']);
                }),
        ];
    }
}
