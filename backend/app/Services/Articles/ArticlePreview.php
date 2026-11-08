<?php

namespace App\Services\Articles;

use App\Http\Resources\ContentAuthorResource;
use App\Models\ContentAuthor;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

final class ArticlePreview
{
    public function document(array $data, array $attachments): string
    {
        Validator::make($data, [
            'title' => ['required', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string', 'max:2000'],
            'cover_alt' => ['nullable', 'string', 'max:255'],
            'published_at' => ['nullable', 'date'],
            'content_author_id' => ['nullable', 'integer', 'exists:content_authors,id'],
        ])->validate();
        $temporary = [];
        $body = $data['body'] ?? '';
        $files = data_get($attachments, 'data.body', []);
        if (is_array($body)) {
            $prepare = function (array &$node) use (&$prepare, &$temporary, $files): void {
                if (($node['type'] ?? null) === 'image') {
                    $id = $node['attrs']['id'] ?? null;
                    if ($id !== null && ! is_string($id)) {
                        $this->invalid('data.body');
                    }
                    if ($id && ! str_starts_with($id, 'article-media/')) {
                        $file = $files[$id] ?? null;
                        $temporary['/article-preview-media/'.count($temporary)] = $this->temporaryUrl($file, 'data.body');
                        $node['attrs']['src'] = array_key_last($temporary);
                        unset($node['attrs']['id']);
                    }
                }
                foreach ($node['content'] ?? [] as $key => $child) {
                    $prepare($node['content'][$key]);
                }
            };
            $prepare($body);
        } elseif (is_string($body)) {
            // Source mode may contain a signed URL created by the current editor upload.
            foreach ($files as $file) {
                if (! $file instanceof TemporaryUploadedFile) {
                    continue;
                }
                $url = $this->temporaryUrl($file, 'data.body');
                $placeholder = '/article-preview-media/'.count($temporary);
                if (str_contains($body, $url) || str_contains($body, e($url))) {
                    $temporary[$placeholder] = $url;
                    $body = str_replace([$url, e($url)], $placeholder, $body);
                }
            }
        } else {
            $this->invalid('data.body');
        }
        $html = app(ArticleHtml::class)->normalize(app(ArticleHtml::class)->fromEditorState($body));
        // Reject unknown local images instead of silently showing a missing upload.
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div>'.$html.'</div>', LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        foreach ($dom->getElementsByTagName('img') as $image) {
            $src = $image->getAttribute('src');
            if (isset($temporary[$src])) {
                continue;
            }
            $path = app(ArticleMediaLifecycle::class)->ownedPath($src, true);
            if ($path) {
                try {
                    app(ArticleMedia::class)->assertRegistered($path);
                } catch (ValidationException) {
                    $this->invalid('data.body');
                }
            } elseif (! preg_match('~\Ahttps://~i', $src)) {
                $this->invalid('data.body');
            }
        }
        app(ArticleMediaLifecycle::class)->assertAttachable(app(ArticleMediaLifecycle::class)->hashes([$html], true));
        $html = app(ArticleMedia::class)->publicHtml($html);
        foreach ($temporary as $placeholder => $url) {
            $html = str_replace('src="'.$placeholder.'"', 'src="'.e($url).'"', $html);
        }
        $html = $this->tables($html);
        $cover = $data['cover_path'] ?? null;
        if (is_array($cover)) {
            if (count($cover) > 1) {
                $this->invalid('data.cover_path');
            }
            $cover = reset($cover) ?: null;
        }
        if ($cover instanceof TemporaryUploadedFile) {
            $cover = $this->temporaryUrl($cover, 'data.cover_path');
        } elseif ($cover) {
            if (! is_string($cover)) {
                $this->invalid('data.cover_path');
            }
            try {
                app(ArticleMedia::class)->assertRegistered($cover);
            } catch (ValidationException) {
                $this->invalid('data.cover_path');
            }
            $cover = app(ArticleMedia::class)->publicUrl($cover);
        }

        return view('filament.article-preview-document', [
            'data' => $data, 'body' => $html, 'cover' => $cover,
            'introduction' => app(ArticleHtml::class)->introduction($data['excerpt'] ?? null, $html),
            'author' => filled($data['content_author_id'] ?? null) && ($author = ContentAuthor::find($data['content_author_id']))
                ? (new ContentAuthorResource($author))->resolve(request()) : null,
        ])->render();
    }

    private function temporaryUrl(mixed $file, string $field): string
    {
        if (! $file instanceof TemporaryUploadedFile || ! $file->exists()
            || $file->getSize() > 20480 * 1024
            || ! in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif'], true)) {
            $this->invalid($field);
        }

        return $file->temporaryUrl();
    }

    private function tables(string $html): string
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div id="preview-table-root">'.$html.'</div>', LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        foreach (iterator_to_array($dom->getElementsByTagName('table')) as $index => $table) {
            $wrapper = $dom->createElement('div');
            $wrapper->setAttribute('class', 'ui-table-scroll');
            $wrapper->setAttribute('role', 'region');
            $wrapper->setAttribute('tabindex', '0');
            $wrapper->setAttribute('aria-label', trim($table->getElementsByTagName('caption')->item(0)?->textContent ?? '') ?: 'Таблица '.($index + 1));
            $table->parentNode->insertBefore($wrapper, $table);
            $wrapper->appendChild($table);
        }
        $root = $dom->getElementById('preview-table-root');

        return implode('', array_map(fn ($node) => $dom->saveHTML($node), iterator_to_array($root->childNodes)));
    }

    private function invalid(string $field): never
    {
        throw ValidationException::withMessages([$field => 'Не удалось показать изображение. Загрузите файл через редактор.']);
    }
}
