<?php

namespace App\Services\Articles;

use App\Filament\RichContent\ArticleContentRenderer;
use App\Filament\RichContent\ArticleOrderedList;
use App\Filament\RichContent\ArticleRichContentPlugin;
use App\Filament\RichContent\ArticleTableSemantics;
use DOMDocument;
use DOMElement;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class ArticleHtml
{
    public function plainText(string $html): string
    {
        $spaced = preg_replace('~</?(?:p|h[1-6]|div|li|tr|td|th|ul|ol|blockquote|br|hr|table|thead|tbody|tfoot|figure|figcaption)\b[^>]*>~i', ' ', $html);

        return trim(preg_replace('/[\s\x{00a0}]+/u', ' ', html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    public function introduction(?string $excerpt, string $body): ?string
    {
        $excerpt = trim($excerpt ?? '');
        $normalized = preg_replace('/[\s\x{00a0}]+/u', ' ', $excerpt);

        return $excerpt === '' || str_starts_with($this->plainText($body), $normalized) ? null : $excerpt;
    }

    public function fromEditorState(string|array|null $state): string
    {
        if (! is_array($state)) {
            return $state ?? '';
        }
        $prepare = function (array &$node) use (&$prepare): void {
            if (($node['type'] ?? null) === 'image' && filled($node['attrs']['id'] ?? null)) {
                $path = $node['attrs']['id'];
                app(ArticleMedia::class)->assertRegistered($path);
                $node['attrs']['src'] = '/storage/'.$path;
                unset($node['attrs']['id']);
            }
            if (isset($node['content'])) {
                foreach ($node['content'] as &$child) {
                    $prepare($child);
                }
            }
        };
        $prepare($state);
        // Filament's generic sanitizer strips iframe, including our allowed video.
        // This intermediate HTML must pass the article sanitizer below before use.
        $html = ArticleContentRenderer::make($state)->plugins([new ArticleRichContentPlugin])->toUnsafeHtml();
        if ($this->plainText($html) === '' && ! preg_match('~<(?:img|iframe|table)\b~i', $html)) {
            return '';
        }

        return $this->sanitize($html);
    }

    public function hasPendingImages(string|array|null $state): bool
    {
        if (! is_array($state)) {
            return false;
        }
        $id = $state['attrs']['id'] ?? null;
        if (($state['type'] ?? null) === 'image' && filled($id) && (! is_string($id) || ! str_starts_with($id, 'article-media/'))) {
            return true;
        }
        foreach ($state['content'] ?? [] as $child) {
            if ($this->hasPendingImages($child)) {
                return true;
            }
        }

        return false;
    }

    public function sanitize(string $html): string
    {
        if (strlen($html) > 2_000_000) {
            throw new \InvalidArgumentException('Article HTML exceeds the supported size.');
        }
        $config = (new HtmlSanitizerConfig)->withMaxInputLength(2_000_000)->allowLinkSchemes(['http', 'https', 'mailto'])->allowMediaSchemes(['https'])->allowRelativeLinks()->allowRelativeMedias();
        foreach (['p', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'strong', 'b', 'em', 'i', 'u', 's', 'ins', 'br', 'hr', 'blockquote', 'figure', 'figcaption', 'sub', 'sup', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'caption', 'div', 'span'] as $tag) {
            $config = $config->allowElement($tag);
        }
        $config = $config->allowElement('ol', ['start'])->allowElement('a', ['href', 'title'])->allowElement('img', ['src', 'alt', 'title', 'width', 'height'])->allowElement('td', ['colspan', 'rowspan'])->allowElement('th', ['colspan', 'rowspan', 'scope'])->allowElement('iframe', ['src', 'title'])->allowElement('div', ['data-article-images']);
        $html = preg_replace(['~<(\/?)h1\b~i', '~<(\/?)ins\b~i'], ['<$1h2', '<$1u'], $html);
        $clean = (new HtmlSanitizer($config))->sanitize($html);
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?><div id="article-root">'.$clean.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        foreach ($dom->getElementsByTagName('ol') as $list) {
            $start = ArticleOrderedList::start($list->getAttribute('start'));
            if ($start === null) {
                $list->removeAttribute('start');
            } else {
                $list->setAttribute('start', (string) $start);
            }
        }
        foreach ($dom->getElementsByTagName('th') as $header) {
            if (ArticleTableSemantics::scope($header->getAttribute('scope')) === null) {
                $header->removeAttribute('scope');
            }
        }
        foreach (iterator_to_array($dom->getElementsByTagName('iframe')) as $frame) {
            if (! preg_match('~\Ahttps://kinescope\.io/embed/[A-Za-z0-9]+\z~D', $frame->getAttribute('src'))) {
                $frame->parentNode->removeChild($frame);
            } else {
                $frame->setAttribute('loading', 'lazy');
                $frame->setAttribute('allowfullscreen', '');
                $frame->setAttribute('title', $frame->getAttribute('title') ?: 'Видео к статье');
            }
        }
        foreach ($dom->getElementsByTagName('img') as $image) {
            if ($image->getAttribute('alt') === '') {
                $image->setAttribute('alt', $image->getAttribute('title'));
            }
            $image->setAttribute('loading', 'lazy');
        }
        foreach ($dom->getElementsByTagName('div') as $block) {
            if ($block->hasAttribute('data-article-images') && $block->getAttribute('data-article-images') !== 'pair') {
                $block->removeAttribute('data-article-images');
            }
        }
        $root = $dom->getElementById('article-root');
        if ($root instanceof DOMElement) {
            $this->documentBlocks($root);
        }

        return $root instanceof DOMElement ? implode('', array_map(fn ($node) => $dom->saveHTML($node), iterator_to_array($root->childNodes))) : '';
    }

    private function documentBlocks(DOMElement $root): void
    {
        foreach (iterator_to_array($root->getElementsByTagName('a')) as $link) {
            if (trim($link->getAttribute('href')) === '') {
                while ($link->firstChild) {
                    $link->parentNode->insertBefore($link->firstChild, $link);
                }
                $link->parentNode->removeChild($link);
            }
        }
        foreach (iterator_to_array($root->getElementsByTagName('p')) as $paragraph) {
            if (! $paragraph->getElementsByTagName('img')->length || $this->insideFigure($paragraph)) {
                continue;
            }
            if ($captioned = $this->inlineCaptionFigure($paragraph)) {
                $paragraph->parentNode->replaceChild($captioned, $paragraph);

                continue;
            }
            $text = $paragraph->cloneNode(false);
            foreach (iterator_to_array($paragraph->childNodes) as $child) {
                foreach ($this->inlineSegments($child, 'img') as [$node, $isImage]) {
                    if (! $isImage) {
                        $text->appendChild($node);

                        continue;
                    }
                    if ($this->hasContent($text)) {
                        $paragraph->parentNode->insertBefore($text, $paragraph);
                    }
                    $paragraph->parentNode->insertBefore($this->figure($node), $paragraph);
                    $text = $paragraph->cloneNode(false);
                }
            }
            if ($this->hasContent($text)) {
                $paragraph->parentNode->insertBefore($text, $paragraph);
            }
            $paragraph->parentNode->removeChild($paragraph);
        }
        foreach (iterator_to_array($root->getElementsByTagName('img')) as $image) {
            if ($this->insideFigure($image)) {
                continue;
            }
            $visual = $image;
            while ($visual->parentNode instanceof DOMElement
                && in_array($visual->parentNode->tagName, ['a', 'span', 'strong', 'b', 'em', 'i', 'u', 's'], true)
                && trim($visual->parentNode->textContent) === ''
                && $visual->parentNode->getElementsByTagName('img')->length === 1) {
                $visual = $visual->parentNode;
            }
            $visual->parentNode->replaceChild($this->figure($visual->cloneNode(true)), $visual);
        }
        $this->removeReadAlsoBlocks($root);
        $xpath = new \DOMXPath($root->ownerDocument);
        foreach (iterator_to_array($root->getElementsByTagName('p')) as $paragraph) {
            $texts = iterator_to_array($xpath->query('.//text()', $paragraph));
            foreach ($texts as $text) {
                $text->nodeValue = preg_replace('/^[\s\x{00a0}]+/u', '', $text->nodeValue);
                if ($text->nodeValue !== '') {
                    break;
                }
            }
            foreach (array_reverse($texts) as $text) {
                $text->nodeValue = preg_replace('/[\s\x{00a0}]+$/u', '', $text->nodeValue);
                if ($text->nodeValue !== '') {
                    break;
                }
            }
            if (! $this->hasContent($paragraph)) {
                $paragraph->parentNode->removeChild($paragraph);
            }
        }
        // Keep this deliberately narrow: cellular "sources" inside medical text are not bibliography.
        $list = $root->lastElementChild;
        $heading = $list?->previousElementSibling;
        if (in_array($list?->tagName, ['ul', 'ol'], true)
            && $heading?->tagName === 'h2' && trim($heading->textContent) === 'Источники') {
            $heading->setAttribute('data-article-sources', '');
        }
    }

    private function removeReadAlsoBlocks(DOMElement $root): void
    {
        foreach (iterator_to_array($root->childNodes) as $paragraph) {
            if (! $paragraph instanceof DOMElement || $paragraph->tagName !== 'p'
                || ! $paragraph->getElementsByTagName('a')->length
                || ! preg_match('/Читайте[\s\x{00a0}]+также/iu', $paragraph->textContent)) {
                continue;
            }
            $lines = [$paragraph->cloneNode(false)];
            foreach ($paragraph->childNodes as $child) {
                foreach ($this->inlineSegments($child, 'br') as [$part, $isBreak]) {
                    if ($isBreak) {
                        $lines[] = $paragraph->cloneNode(false);
                    } else {
                        $lines[array_key_last($lines)]->appendChild($part);
                    }
                }
            }
            $kept = array_values(array_filter($lines, fn ($line) => ! (
                preg_match('/\A[\s\x{00a0}]*(?:И[\s\x{00a0}]+)?Читайте[\s\x{00a0}]+также/iu', $line->textContent)
                && $line->getElementsByTagName('a')->length
            )));
            if (count($kept) === count($lines)) {
                continue;
            }
            while ($kept && ! $this->hasContent($kept[array_key_last($kept)])) {
                array_pop($kept);
            }
            while ($paragraph->firstChild) {
                $paragraph->removeChild($paragraph->firstChild);
            }
            foreach ($kept as $index => $line) {
                if ($index) {
                    $paragraph->appendChild($root->ownerDocument->createElement('br'));
                }
                while ($line->firstChild) {
                    $paragraph->appendChild($line->firstChild);
                }
            }
            if (! $this->hasContent($paragraph)) {
                $root->removeChild($paragraph);
            }
        }
    }

    private function inlineCaptionFigure(DOMElement $paragraph): ?DOMElement
    {
        $parts = array_values(array_filter(iterator_to_array($paragraph->childNodes),
            fn ($node) => ! ($node instanceof \DOMText && ! preg_match('/[^\s\x{00a0}]/u', $node->textContent))));
        $image = $parts[0] ?? null;
        $label = $parts[1] ?? null;
        // Only the existing single-image / following-em pattern, never neighbouring paragraphs.
        if (count($parts) !== 2 || ! $image instanceof DOMElement || $image->tagName !== 'img'
            || $image->getAttribute('title') !== '' || ! $label instanceof DOMElement || $label->tagName !== 'em'
            || ! preg_match('/[^\s\x{00a0}]/u', $label->textContent)
            || $label->getElementsByTagName('img')->length || $label->getElementsByTagName('iframe')->length) {
            return null;
        }
        $visual = $image->cloneNode(true);
        if ($visual->getAttribute('alt') === '') {
            $visual->setAttribute('alt', $this->plainText($paragraph->ownerDocument->saveHTML($label)));
        }
        $figure = $this->figure($visual);
        $caption = $paragraph->ownerDocument->createElement('figcaption');
        $caption->appendChild($label->cloneNode(true));
        $figure->appendChild($caption);

        return $figure;
    }

    private function hasContent(DOMElement $node): bool
    {
        return preg_match('/[^\s\x{00a0}]/u', $node->textContent)
            || $node->getElementsByTagName('img')->length || $node->getElementsByTagName('iframe')->length;
    }

    private function insideFigure(\DOMNode $node): bool
    {
        for ($parent = $node->parentNode; $parent instanceof DOMElement; $parent = $parent->parentNode) {
            if ($parent->tagName === 'figure') {
                return true;
            }
        }

        return false;
    }

    /** Split inline markup around structural nodes without moving text, links or emphasis. */
    private function inlineSegments(\DOMNode $node, string $tag): array
    {
        if (! $node instanceof DOMElement || $node->tagName === $tag || ! $node->getElementsByTagName($tag)->length) {
            return [[$node->cloneNode(true), $node instanceof DOMElement && $node->tagName === $tag]];
        }
        $segments = [];
        $text = $node->cloneNode(false);
        foreach ($node->childNodes as $child) {
            foreach ($this->inlineSegments($child, $tag) as [$part, $isStructural]) {
                if (! $isStructural) {
                    $text->appendChild($part);

                    continue;
                }
                if ($text->hasChildNodes()) {
                    $segments[] = [$text, false];
                }
                $visual = $node->cloneNode(false);
                $visual->appendChild($part);
                $segments[] = [$visual, true];
                $text = $node->cloneNode(false);
            }
        }
        if ($text->hasChildNodes()) {
            $segments[] = [$text, false];
        }

        return $segments;
    }

    private function figure(\DOMNode $visual): DOMElement
    {
        $dom = $visual->ownerDocument;
        $figure = $dom->createElement('figure');
        $figure->appendChild($visual);
        $image = $visual instanceof DOMElement && $visual->tagName === 'img' ? $visual : $figure->getElementsByTagName('img')->item(0);
        if ($image instanceof DOMElement && trim($image->getAttribute('title')) !== '') {
            $caption = $dom->createElement('figcaption');
            $caption->appendChild($dom->createTextNode($image->getAttribute('title')));
            $figure->appendChild($caption);
        }

        return $figure;
    }

    public function normalize(string $html): string
    {
        return $this->sanitize(str_replace(rtrim(config('app.url'), '/').'/storage/article-media/', '/storage/article-media/', $html));
    }
}
