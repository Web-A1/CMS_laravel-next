<?php

namespace Tests\Unit;

use App\Models\LessonLearningContent;
use App\Services\Learning\LessonBodyContract;
use Tests\TestCase;

class LessonBodyContractTest extends TestCase
{
    public function test_returns_student_payload_for_safe_lesson_blocks(): void
    {
        $payload = $this->contract()->toStudentPayload($this->publishedContent([
            'blocks' => [
                [
                    'type' => 'heading',
                    'level' => 2,
                    'id' => 'intro',
                    'text' => 'Введение',
                ],
                [
                    'type' => 'paragraph',
                    'text' => 'Учебный текст.',
                ],
            ],
            'toc' => [
                [
                    'id' => 'intro',
                    'title' => 'Введение',
                ],
            ],
        ]));

        $this->assertSame('published', $payload['status'] ?? null);
        $this->assertSame('lesson_blocks_v1', $payload['format'] ?? null);
        $this->assertSame('heading', data_get($payload, 'body.blocks.0.type'));
        $this->assertSame('Введение', data_get($payload, 'body.blocks.0.text'));
        $this->assertSame('intro', data_get($payload, 'body.toc.0.id'));
    }

    public function test_blocks_raw_legacy_shortcodes(): void
    {
        $payload = $this->contract()->toStudentPayload($this->publishedContent([
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'text' => 'Файл {file::123}',
                ],
            ],
            'toc' => [],
        ]));

        $this->assertNull($payload);
    }

    public function test_blocks_internal_legacy_protected_asset_references(): void
    {
        $payload = $this->contract()->toStudentPayload($this->publishedContent([
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'text' => 'Текст перед материалом.',
                ],
                [
                    'type' => 'legacy_protected_asset_reference',
                    'assetKind' => 'legacy_video',
                    'legacyAssetId' => 55,
                    'origin' => 'legacy_shortcode',
                ],
            ],
            'toc' => [],
        ]));

        $this->assertNull($payload);
    }

    public function test_blocks_nested_forbidden_media_keys(): void
    {
        $payload = $this->contract()->toStudentPayload($this->publishedContent([
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'text' => 'Учебный текст.',
                    'attrs' => [
                        'signedUrl' => 'https://cdn.example.test/private.mp4',
                    ],
                ],
            ],
            'toc' => [],
        ]));

        $this->assertNull($payload);
    }

    public function test_blocks_direct_protected_material_urls_in_text(): void
    {
        $payload = $this->contract()->toStudentPayload($this->publishedContent([
            'blocks' => [
                [
                    'type' => 'paragraph',
                    'text' => 'Скачать конспект: https://cdn.example.test/private/lesson.pdf',
                ],
            ],
            'toc' => [],
        ]));

        $this->assertNull($payload);
    }

    public function test_blocks_unknown_or_empty_body_shape(): void
    {
        $this->assertNull($this->contract()->toStudentPayload($this->publishedContent([
            'blocks' => [
                [
                    'type' => 'embed',
                    'url' => 'https://example.test',
                ],
            ],
            'toc' => [],
        ])));

        $this->assertNull($this->contract()->toStudentPayload($this->publishedContent([
            'blocks' => [],
            'toc' => [],
        ])));
    }

    private function contract(): LessonBodyContract
    {
        return new LessonBodyContract;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function publishedContent(array $body): LessonLearningContent
    {
        return new LessonLearningContent([
            'status' => LessonLearningContent::STATUS_PUBLISHED,
            'format' => LessonLearningContent::FORMAT_LESSON_BLOCKS_V1,
            'version' => 1,
            'body' => $body,
            'published_at' => now(),
        ]);
    }
}
