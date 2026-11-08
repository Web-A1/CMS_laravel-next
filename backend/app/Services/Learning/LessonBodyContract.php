<?php

namespace App\Services\Learning;

use App\Models\LessonLearningContent;

class LessonBodyContract
{
    /**
     * @var array<int, string>
     */
    private const FORBIDDEN_KEYS = [
        'contentUrl',
        'downloadUrl',
        'fileUrl',
        'files',
        'media',
        'mediaUrl',
        'providerId',
        'signedUrl',
        'source_body',
        'storageKey',
        'video',
        'videoUrl',
    ];

    private const SHORTCODE_PATTERN = '/\{(?:file|video)::\d+\}/i';

    private const PROTECTED_MATERIAL_URL_PATTERN = '~(?:https?:)?//[^\s<>"\']*(?:/(?:storage|private|protected)/|[?&](?:X-Amz-Signature|signature|token|expires)=)|(?:https?:)?//[^\s<>"\']+\.(?:pdf|mp4|m4v|mov|webm|mp3|m4a|zip|doc|docx|ppt|pptx|xls|xlsx)(?=$|[\s<>"\'.),;:!?]|[?#])|/(?:storage|private|protected)/[^\s<>"\']+~iu';

    /**
     * @return array<string, mixed>|null
     */
    public function toStudentPayload(?LessonLearningContent $content): ?array
    {
        if (! $content instanceof LessonLearningContent) {
            return null;
        }

        if (
            $content->status !== LessonLearningContent::STATUS_PUBLISHED
            || $content->format !== LessonLearningContent::FORMAT_LESSON_BLOCKS_V1
        ) {
            return null;
        }

        $body = $this->normalizeBody($content->body);

        if ($body === null) {
            return null;
        }

        return [
            'status' => LessonLearningContent::STATUS_PUBLISHED,
            'format' => LessonLearningContent::FORMAT_LESSON_BLOCKS_V1,
            'version' => $content->version,
            'publishedAt' => $content->published_at?->toJSON(),
            'body' => $body,
        ];
    }

    /**
     * @return array{blocks: array<int, array<string, mixed>>, toc: array<int, array{id: string, title: string}>}|null
     */
    public function normalizeBody(mixed $body): ?array
    {
        if (! is_array($body) || ! $this->isStudentSafeValue($body)) {
            return null;
        }

        $rawBlocks = $body['blocks'] ?? null;
        if (! is_array($rawBlocks) || $rawBlocks === []) {
            return null;
        }

        $blocks = [];
        foreach ($rawBlocks as $rawBlock) {
            $block = $this->normalizeBlock($rawBlock);

            if ($block === null) {
                return null;
            }

            $blocks[] = $block;
        }

        return [
            'blocks' => $blocks,
            'toc' => $this->normalizeToc($body['toc'] ?? []),
        ];
    }

    public function containsBlockedLegacyReference(string $value): bool
    {
        return preg_match(self::SHORTCODE_PATTERN, $value) === 1;
    }

    private function containsBlockedProtectedMaterialUrl(string $value): bool
    {
        return preg_match(self::PROTECTED_MATERIAL_URL_PATTERN, $value) === 1;
    }

    private function isStudentSafeValue(mixed $value): bool
    {
        if (is_string($value)) {
            return ! $this->containsBlockedLegacyReference($value)
                && ! $this->containsBlockedProtectedMaterialUrl($value);
        }

        if (! is_array($value)) {
            return true;
        }

        foreach ($value as $key => $nestedValue) {
            if (is_string($key) && in_array($key, self::FORBIDDEN_KEYS, true)) {
                return false;
            }

            if (! $this->isStudentSafeValue($nestedValue)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeBlock(mixed $rawBlock): ?array
    {
        if (! is_array($rawBlock)) {
            return null;
        }

        $type = $rawBlock['type'] ?? null;
        if (! is_string($type)) {
            return null;
        }

        return match ($type) {
            'callout' => $this->normalizeTextBlock($rawBlock, 'callout', optionalFields: ['tone']),
            'divider' => ['type' => 'divider'],
            'heading' => $this->normalizeHeadingBlock($rawBlock),
            'list' => $this->normalizeListBlock($rawBlock),
            'paragraph' => $this->normalizeTextBlock($rawBlock, 'paragraph'),
            'quote' => $this->normalizeTextBlock($rawBlock, 'quote'),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $rawBlock
     * @param  array<int, string>  $optionalFields
     * @return array<string, mixed>|null
     */
    private function normalizeTextBlock(array $rawBlock, string $type, array $optionalFields = []): ?array
    {
        $text = $rawBlock['text'] ?? null;
        if (! is_string($text) || trim($text) === '') {
            return null;
        }

        $block = [
            'type' => $type,
            'text' => trim($text),
        ];

        foreach ($optionalFields as $field) {
            if (isset($rawBlock[$field]) && is_string($rawBlock[$field]) && trim($rawBlock[$field]) !== '') {
                $block[$field] = trim($rawBlock[$field]);
            }
        }

        return $block;
    }

    /**
     * @param  array<string, mixed>  $rawBlock
     * @return array<string, mixed>|null
     */
    private function normalizeHeadingBlock(array $rawBlock): ?array
    {
        $block = $this->normalizeTextBlock($rawBlock, 'heading');

        if ($block === null) {
            return null;
        }

        $level = $rawBlock['level'] ?? 2;
        $block['level'] = is_int($level) && $level >= 2 && $level <= 4 ? $level : 2;

        if (isset($rawBlock['id']) && is_string($rawBlock['id']) && preg_match('/^[a-z0-9][a-z0-9-]{0,119}$/', $rawBlock['id']) === 1) {
            $block['id'] = $rawBlock['id'];
        }

        return $block;
    }

    /**
     * @param  array<string, mixed>  $rawBlock
     * @return array{type: string, items: array<int, string>}|null
     */
    private function normalizeListBlock(array $rawBlock): ?array
    {
        $items = $rawBlock['items'] ?? null;
        if (! is_array($items)) {
            return null;
        }

        $normalizedItems = [];
        foreach ($items as $item) {
            if (! is_string($item) || trim($item) === '') {
                return null;
            }

            $normalizedItems[] = trim($item);
        }

        if ($normalizedItems === []) {
            return null;
        }

        return [
            'type' => 'list',
            'items' => $normalizedItems,
        ];
    }

    /**
     * @return array<int, array{id: string, title: string}>
     */
    private function normalizeToc(mixed $rawToc): array
    {
        if (! is_array($rawToc)) {
            return [];
        }

        $toc = [];
        foreach ($rawToc as $item) {
            if (! is_array($item)) {
                continue;
            }

            $id = $item['id'] ?? null;
            $title = $item['title'] ?? null;

            if (
                is_string($id)
                && preg_match('/^[a-z0-9][a-z0-9-]{0,119}$/', $id) === 1
                && is_string($title)
                && trim($title) !== ''
            ) {
                $toc[] = [
                    'id' => $id,
                    'title' => trim($title),
                ];
            }
        }

        return $toc;
    }
}
