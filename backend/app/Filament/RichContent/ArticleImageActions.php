<?php

namespace App\Filament\RichContent;

use App\Services\Articles\ArticleEditor;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\EditorCommand;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

final class ArticleImageActions
{
    public static function make(bool $pair): Action
    {
        $label = $pair ? 'Два изображения рядом' : 'Одно изображение';
        $slots = $pair ? ['first', 'second'] : ['first'];

        return Action::make($pair ? 'articleImagePair' : 'articleImage')->label($label)
            ->modalHeading(fn (array $arguments): string => ! $pair && filled($arguments['src'] ?? null) ? 'Изменить изображение' : $label)
            ->schema(function (array $arguments, RichEditor $component) use ($pair, $slots): array {
                $editing = ! $pair && filled($arguments['src'] ?? null);
                $fields = [];
                foreach ($slots as $slot) {
                    $suffix = $pair ? ($slot === 'first' ? ' первого изображения' : ' второго изображения') : '';
                    $fields[] = FileUpload::make($slot.'_file')
                        ->label($editing ? 'Заменить файл' : ($pair ? ($slot === 'first' ? 'Первое изображение' : 'Второе изображение') : 'Изображение'))
                        ->acceptedFileTypes($component->getFileAttachmentsAcceptedFileTypes())
                        ->maxSize($component->getFileAttachmentsMaxSize())
                        ->storeFiles(false)
                        ->required(! $editing);
                    if (! $editing) {
                        $fields[] = TextInput::make($slot.'_caption')->label('Подпись'.$suffix)->maxLength(2000);
                    }
                }

                return $fields;
            })
            ->action(function (array $arguments, array $data, RichEditor $component, Component $livewire) use ($pair, $slots): void {
                app(ArticleEditor::class)->actor();
                $selection = $arguments['editorSelection'] ?? null;
                if (! $pair && filled($arguments['src'] ?? null)) {
                    $attrs = self::imageAttributes($data, 'first', $component, $livewire, $arguments);
                    if (($selection['type'] ?? null) === 'text' && isset($selection['anchor'])) {
                        // Filament can report the selected inline image as a text position after it.
                        $selection = ['type' => 'node', 'anchor' => $selection['anchor'] - 1];
                    }
                    $component->runCommands([EditorCommand::make('updateAttributes', arguments: ['image', $attrs])], editorSelection: $selection);

                    return;
                }

                $figures = [];
                foreach ($slots as $slot) {
                    $children = [['type' => 'image', 'attrs' => self::imageAttributes($data, $slot, $component, $livewire)]];
                    if (filled($data[$slot.'_caption'] ?? null)) {
                        $children[] = ['type' => 'articleFigcaption', 'content' => [['type' => 'text', 'text' => $data[$slot.'_caption']]]];
                    }
                    $figures[] = ['type' => 'articleFigure', 'content' => $children];
                }
                $block = $pair ? ['type' => 'articleImagePair', 'content' => $figures] : $figures[0];
                $component->runCommands([EditorCommand::make('insertArticleImageBlock', arguments: [$block])], editorSelection: $selection);
            });
    }

    private static function imageAttributes(array $data, string $slot, RichEditor $component, Component $livewire, array $existing = []): array
    {
        $file = $data[$slot.'_file'] ?? null;
        $id = $existing['id'] ?? null;
        $src = $existing['src'] ?? null;
        if ($file instanceof TemporaryUploadedFile) {
            $id = (string) Str::orderedUuid();
            data_set($livewire, "componentFileAttachments.{$component->getStatePath()}.{$id}", $file);
            $src = $component->getUploadedFileAttachmentTemporaryUrl($file);
        }

        return ['id' => $id, 'src' => $src, 'alt' => $data[$slot.'_caption'] ?? $existing['alt'] ?? null];
    }
}
