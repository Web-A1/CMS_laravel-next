<?php

namespace App\Services\Learning;

use App\Models\LessonAssessment;
use App\Models\LessonAssessmentAsset;
use App\Models\LessonAssessmentVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class LessonAssessmentPublisher
{
    private const ASSET_MIME_EXTENSIONS = [
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly LessonAssessmentContentValidator $validator) {}

    /**
     * @param  array<string, mixed>  $validatedFixture
     */
    public function publish(LessonAssessmentVersion $version, array $validatedFixture): LessonAssessmentVersion
    {
        $fixture = $this->validator->validate($validatedFixture);
        $this->assertVersionMatchesFixture($version, $fixture);
        $assetMetadata = $this->verifyAndStoreAssets($version, $fixture);

        return DB::transaction(function () use ($version, $fixture, $assetMetadata): LessonAssessmentVersion {
            $assessment = LessonAssessment::query()
                ->lockForUpdate()
                ->findOrFail($version->lesson_assessment_id);
            $lockedVersion = LessonAssessmentVersion::query()
                ->lockForUpdate()
                ->findOrFail($version->id);

            if ($lockedVersion->lesson_assessment_id !== $assessment->id) {
                throw new RuntimeException('Lesson assessment version does not belong to the requested assessment.');
            }

            $this->assertVersionMatchesFixture($lockedVersion, $fixture);
            if (! in_array($lockedVersion->status, [
                LessonAssessmentVersion::STATUS_DRAFT,
                LessonAssessmentVersion::STATUS_PUBLISHED,
            ], true)) {
                throw new RuntimeException('Lesson assessment version cannot be published.');
            }

            if ($lockedVersion->status === LessonAssessmentVersion::STATUS_PUBLISHED) {
                $this->assertExactAssetRegistry($lockedVersion, $assetMetadata);

                return $lockedVersion->refresh();
            }

            LessonAssessmentVersion::query()
                ->where('lesson_assessment_id', $assessment->id)
                ->where('status', LessonAssessmentVersion::STATUS_PUBLISHED)
                ->whereKeyNot($lockedVersion->id)
                ->lockForUpdate()
                ->each(function (LessonAssessmentVersion $publishedVersion): void {
                    $publishedVersion->status = LessonAssessmentVersion::STATUS_RETIRED;
                    $publishedVersion->save();
                });

            $this->synchronizeDraftAssetRegistry($lockedVersion, $assetMetadata);
            $this->assertExactAssetRegistry($lockedVersion, $assetMetadata);

            $lockedVersion->status = LessonAssessmentVersion::STATUS_PUBLISHED;
            $lockedVersion->published_at = now();
            $lockedVersion->save();

            return $lockedVersion->refresh();
        });
    }

    /**
     * @param  array{
     *     schemaVersion: int,
     *     contentVersion: int,
     *     sources: array{assignmentSha256: string, keySha256: string},
     *     sections: list<array{id: string, title: string}>,
     *     questions: list<array<string, mixed>>,
     *     answerKey: array<string, array{response: array<string, mixed>, source: array{page: int, item: string}}>
     * }  $fixture
     */
    private function assertVersionMatchesFixture(LessonAssessmentVersion $version, array $fixture): void
    {
        if (
            $version->schema_version !== $fixture['schemaVersion']
            || $version->content_version !== $fixture['contentVersion']
            || $this->canonicalizeJson($version->scoring_policy) !== $this->canonicalizeJson($fixture['scoringPolicy'])
            || ! hash_equals($fixture['sources']['assignmentSha256'], $version->source_assignment_sha256)
            || ! hash_equals($fixture['sources']['keySha256'], $version->source_key_sha256)
            || $this->canonicalizeJson($version->sections) !== $this->canonicalizeJson($fixture['sections'])
            || $this->canonicalizeJson($version->questions) !== $this->canonicalizeJson($fixture['questions'])
            || $this->canonicalizeJson($version->answer_key) !== $this->canonicalizeJson($fixture['answerKey'])
        ) {
            throw new RuntimeException('Lesson assessment draft does not match the canonical fixture.');
        }
    }

    private function canonicalizeJson(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map($this->canonicalizeJson(...), $value);
        }

        $canonical = [];
        foreach ($value as $key => $item) {
            $canonical[$key] = $this->canonicalizeJson($item);
        }
        ksort($canonical, SORT_STRING);

        return $canonical;
    }

    /**
     * @param  list<array{asset_key: string, disk: string, object_key: string, sha256: string, mime_type: string, width: int, height: int, sort_order: int}>  $assetMetadata
     */
    private function synchronizeDraftAssetRegistry(LessonAssessmentVersion $version, array $assetMetadata): void
    {
        $canonicalKeys = array_column($assetMetadata, 'asset_key');
        $version->assets()
            ->lockForUpdate()
            ->get()
            ->reject(fn (LessonAssessmentAsset $asset): bool => in_array($asset->asset_key, $canonicalKeys, true))
            ->each->delete();

        foreach ($assetMetadata as $asset) {
            $version->assets()->updateOrCreate(
                ['asset_key' => $asset['asset_key']],
                $asset,
            );
        }
    }

    /**
     * @param  list<array{asset_key: string, disk: string, object_key: string, sha256: string, mime_type: string, width: int, height: int, sort_order: int}>  $assetMetadata
     */
    private function assertExactAssetRegistry(LessonAssessmentVersion $version, array $assetMetadata): void
    {
        $assets = $version->assets()->lockForUpdate()->get()->keyBy('asset_key');

        if ($assets->count() !== count($assetMetadata)) {
            throw new RuntimeException('Lesson assessment asset registry does not match the canonical fixture.');
        }

        foreach ($assetMetadata as $metadata) {
            $asset = $assets->get($metadata['asset_key']);
            if (
                ! $asset instanceof LessonAssessmentAsset
                || $asset->disk !== $metadata['disk']
                || $asset->object_key !== $metadata['object_key']
                || ! hash_equals($metadata['sha256'], $asset->sha256)
                || $asset->mime_type !== $metadata['mime_type']
                || $asset->width !== $metadata['width']
                || $asset->height !== $metadata['height']
                || $asset->sort_order !== $metadata['sort_order']
            ) {
                throw new RuntimeException('Lesson assessment asset registry does not match the canonical fixture.');
            }
        }
    }

    /**
     * @param  array{
     *     contentVersion: int,
     *     assets: list<array{assetKey: string, sourcePath: string, sha256: string, mimeType: string, width: int, height: int}>
     * }  $fixture
     * @return list<array{asset_key: string, disk: string, object_key: string, sha256: string, mime_type: string, width: int, height: int, sort_order: int}>
     */
    private function verifyAndStoreAssets(LessonAssessmentVersion $version, array $fixture): array
    {
        $diskName = (string) config('protected_files.source_disk');
        $disk = Storage::disk($diskName);
        $assets = [];

        foreach ($fixture['assets'] as $sortOrder => $asset) {
            $sourcePath = database_path('seeders/assets/'.$asset['sourcePath']);
            if (! is_file($sourcePath) || ! hash_equals($asset['sha256'], (string) hash_file('sha256', $sourcePath))) {
                throw new RuntimeException('Lesson assessment asset source verification failed.');
            }

            $extension = self::ASSET_MIME_EXTENSIONS[$asset['mimeType']] ?? null;
            if ($extension === null) {
                throw new RuntimeException('Lesson assessment asset MIME type is not supported.');
            }

            $objectKey = sprintf(
                'lesson-assessments/%d/v%d/%s.%s',
                $version->lesson_assessment_id,
                $fixture['contentVersion'],
                $asset['sha256'],
                $extension,
            );

            if (! $disk->exists($objectKey)) {
                $stream = fopen($sourcePath, 'rb');
                if ($stream === false) {
                    throw new RuntimeException('Lesson assessment asset source could not be opened.');
                }

                try {
                    if ($disk->put($objectKey, $stream) !== true) {
                        throw new RuntimeException('Lesson assessment asset storage write failed.');
                    }
                } finally {
                    fclose($stream);
                }
            }

            if (! hash_equals($asset['sha256'], hash('sha256', $disk->get($objectKey)))) {
                throw new RuntimeException('Lesson assessment asset storage verification failed.');
            }

            $assets[] = [
                'asset_key' => $asset['assetKey'],
                'disk' => $diskName,
                'object_key' => $objectKey,
                'sha256' => $asset['sha256'],
                'mime_type' => $asset['mimeType'],
                'width' => $asset['width'],
                'height' => $asset['height'],
                'sort_order' => $sortOrder + 1,
            ];
        }

        return $assets;
    }
}
