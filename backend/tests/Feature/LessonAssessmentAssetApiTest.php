<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAssessment;
use App\Models\LessonAssessmentAsset;
use App\Models\LessonAssessmentAttempt;
use App\Models\LessonAssessmentCompletion;
use App\Models\LessonAssessmentVersion;
use App\Models\Subject;
use App\Models\User;
use App\Models\UserEntitlementGrant;
use Database\Seeders\LessonAssessmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LessonAssessmentAssetApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('trusted_browser.mode', 'off');
        Storage::fake('local');
    }

    public function test_asset_requires_authentication_entitlement_and_trusted_browser(): void
    {
        [$subject, $course, $lesson, , $version] = $this->createAssessmentChain();
        $this->createAsset($version, 'diagram-01', 'assets/diagram-01.png', 'png-bytes');
        $url = $this->assetUrl($subject, $course, $lesson, 'diagram-01');

        $this->getJson($url)->assertUnauthorized();

        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->getJson($url)
            ->assertForbidden()
            ->assertExactJson($this->accessDenied());

        $this->grant($user, $lesson);
        config()->set('trusted_browser.mode', 'enforce');
        $this->getJson($url)
            ->assertForbidden()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('error.code', 'TRUSTED_BROWSER_REQUIRED');
    }

    public function test_failed_attempt_asset_streams_historical_version_bytes_with_private_security_headers(): void
    {
        $user = User::factory()->create();
        [$subject, $course, $lesson, $assessment, $versionOne] = $this->createAssessmentChain();
        $versionOne->forceFill(['status' => LessonAssessmentVersion::STATUS_RETIRED])->save();
        $versionTwo = $this->createVersion($assessment, 2, LessonAssessmentVersion::STATUS_PUBLISHED);
        $this->createAsset($versionOne, 'diagram-01', 'assets/v1.png', 'version-one-bytes');
        $this->createAsset($versionTwo, 'diagram-01', 'assets/v2.png', 'version-two-bytes');
        $attempt = $this->createAttempt($user, $assessment, $versionOne);
        $attempt->forceFill([
            'status' => LessonAssessmentAttempt::STATUS_FAILED,
            'check_count' => 1,
            'checked_at' => now(),
        ])->save();
        $this->grant($user, $lesson);
        Sanctum::actingAs($user);

        $response = $this->get($this->assetUrl($subject, $course, $lesson, 'diagram-01'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertSame('version-one-bytes', $response->streamedContent());
        $this->assertStringNotContainsString('http', $response->headers->get('Location', ''));
    }

    public function test_asset_streams_webp_with_private_security_headers(): void
    {
        $user = User::factory()->create();
        [$subject, $course, $lesson, , $version] = $this->createAssessmentChain();
        $this->createAsset($version, 'diagram-01', 'assets/diagram-01.webp', 'webp-bytes', 'image/webp');
        $this->grant($user, $lesson);
        Sanctum::actingAs($user);

        $response = $this->get($this->assetUrl($subject, $course, $lesson, 'diagram-01'))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertSame('webp-bytes', $response->streamedContent());
    }

    public function test_active_retake_asset_takes_priority_over_completed_attempt_asset(): void
    {
        $user = User::factory()->create();
        [$subject, $course, $lesson, $assessment, $completedVersion] = $this->createAssessmentChain();
        $completedVersion->forceFill(['status' => LessonAssessmentVersion::STATUS_RETIRED])->save();
        $retakeVersion = $this->createVersion($assessment, 2, LessonAssessmentVersion::STATUS_PUBLISHED);
        $this->createAsset($completedVersion, 'diagram-01', 'assets/completed.png', 'completed-bytes');
        $this->createAsset($retakeVersion, 'diagram-01', 'assets/retake.png', 'retake-bytes');

        $passedAttempt = $this->createAttempt($user, $assessment, $completedVersion);
        $passedAttempt->forceFill([
            'status' => LessonAssessmentAttempt::STATUS_PASSED,
            'passed_at' => now(),
        ])->save();
        LessonAssessmentCompletion::query()->forceCreate([
            'user_id' => $user->id,
            'lesson_id' => $lesson->id,
            'lesson_assessment_id' => $assessment->id,
            'passed_attempt_id' => $passedAttempt->id,
            'completed_at' => now(),
        ]);
        $this->createAttempt($user, $assessment, $retakeVersion);
        $this->grant($user, $lesson);
        Sanctum::actingAs($user);

        $response = $this->get($this->assetUrl($subject, $course, $lesson, 'diagram-01'))->assertOk();

        $this->assertSame('retake-bytes', $response->streamedContent());
    }

    public function test_asset_is_resolved_only_inside_selected_version_and_unknown_asset_is_not_found(): void
    {
        $user = User::factory()->create();
        [$subject, $course, $lesson, $assessment, $versionOne] = $this->createAssessmentChain();
        $versionOne->forceFill(['status' => LessonAssessmentVersion::STATUS_RETIRED])->save();
        $versionTwo = $this->createVersion($assessment, 2, LessonAssessmentVersion::STATUS_PUBLISHED);
        $this->createAsset($versionTwo, 'only-v2', 'assets/v2-only.png', 'version-two-bytes');
        $this->createAttempt($user, $assessment, $versionOne);
        $this->grant($user, $lesson);
        Sanctum::actingAs($user);

        foreach (['only-v2', 'missing'] as $assetKey) {
            $this->getJson($this->assetUrl($subject, $course, $lesson, $assetKey))
                ->assertNotFound()
                ->assertExactJson($this->assetNotFound());
        }

        $this->getJson($this->assetUrl($subject, $course, $lesson, '..%2F.env'))
            ->assertNotFound();
    }

    public function test_assessment_json_never_exposes_asset_storage_locators(): void
    {
        $user = User::factory()->create();
        [$subject, $course, $lesson, , $version] = $this->createAssessmentChain();
        $this->createAsset($version, 'diagram-01', 'private/storage-sentinel.png', 'png-bytes');
        $this->grant($user, $lesson);
        Sanctum::actingAs($user);

        $response = $this->getJson($this->assessmentUrl($subject, $course, $lesson))->assertOk();
        $payload = (string) $response->getContent();

        $response->assertJsonPath(
            'data.assessment.questions.0.assetPath',
            $this->assessmentUrl($subject, $course, $lesson).'/assets/diagram-01',
        );
        $this->assertDoesNotMatchRegularExpression('/private\/storage-sentinel|object[_-]?key|storage|"disk"/i', $payload);
    }

    /** @return array{Subject, Course, Lesson, LessonAssessment, LessonAssessmentVersion} */
    private function createAssessmentChain(): array
    {
        $subject = Subject::query()->create([
            'slug' => 'asset-subject-'.uniqid(),
            'title' => 'Subject',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => 'asset-course-'.uniqid(),
            'title' => 'Course',
            'price_cents' => 1000,
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'asset-lesson-'.uniqid(),
            'title' => 'Lesson',
            'sort_order' => 1,
            'is_active' => true,
            'is_free_preview' => false,
        ]);
        $assessment = LessonAssessment::query()->create(['lesson_id' => $lesson->id]);
        $version = $this->createVersion($assessment, 1, LessonAssessmentVersion::STATUS_PUBLISHED);

        return [$subject, $course, $lesson, $assessment, $version];
    }

    private function createVersion(
        LessonAssessment $assessment,
        int $contentVersion,
        string $status,
    ): LessonAssessmentVersion {
        return LessonAssessmentVersion::query()->forceCreate([
            'lesson_assessment_id' => $assessment->id,
            'status' => $status,
            'schema_version' => 2,
            'content_version' => $contentVersion,
            'scoring_policy' => LessonAssessmentSeeder::validatedFixture()['scoringPolicy'],
            'source_assignment_sha256' => str_repeat((string) $contentVersion, 64),
            'source_key_sha256' => str_repeat((string) ($contentVersion + 1), 64),
            'sections' => [[
                'id' => 'diagrams',
                'title' => "Diagram section v{$contentVersion}",
            ]],
            'questions' => [[
                'id' => 'diagram-01',
                'sectionId' => 'diagrams',
                'type' => 'diagram_labels',
                'gradingMode' => 'automatic',
                'prompt' => 'Diagram',
                'source' => ['page' => 1, 'item' => '1'],
                'assetKey' => 'diagram-01',
                'options' => [
                    ['id' => 'o1', 'label' => 'One'],
                    ['id' => 'o2', 'label' => 'Two'],
                ],
                'parts' => [
                    ['id' => 'name', 'prompt' => 'Name', 'optionIds' => ['o1', 'o2']],
                    ['id' => 'p01', 'prompt' => 'Part', 'optionIds' => ['o1', 'o2']],
                ],
                'callouts' => [['partId' => 'p01', 'x' => 0.5, 'y' => 0.5]],
                'distractorOptionIds' => ['o2'],
            ]],
            'answer_key' => [
                'diagram-01' => ['response' => ['selections' => ['name' => 'o1', 'p01' => 'o2']]],
            ],
            'published_at' => $status === LessonAssessmentVersion::STATUS_PUBLISHED ? now() : null,
        ]);
    }

    private function createAsset(
        LessonAssessmentVersion $version,
        string $assetKey,
        string $objectKey,
        string $bytes,
        string $mimeType = 'image/png',
    ): LessonAssessmentAsset {
        Storage::disk('local')->put($objectKey, $bytes);

        return LessonAssessmentAsset::query()->create([
            'lesson_assessment_version_id' => $version->id,
            'asset_key' => $assetKey,
            'disk' => 'local',
            'object_key' => $objectKey,
            'sha256' => hash('sha256', $bytes),
            'mime_type' => $mimeType,
            'width' => 640,
            'height' => 480,
            'sort_order' => 1,
        ]);
    }

    private function createAttempt(
        User $user,
        LessonAssessment $assessment,
        LessonAssessmentVersion $version,
    ): LessonAssessmentAttempt {
        return LessonAssessmentAttempt::query()->forceCreate([
            'user_id' => $user->id,
            'lesson_assessment_id' => $assessment->id,
            'lesson_assessment_version_id' => $version->id,
            'status' => LessonAssessmentAttempt::STATUS_IN_PROGRESS,
            'correct_count' => 0,
            'revision' => 0,
            'check_count' => 0,
        ]);
    }

    private function grant(User $user, Lesson $lesson): void
    {
        UserEntitlementGrant::query()->create([
            'user_id' => $user->id,
            'atom_kind' => UserEntitlementGrant::ATOM_LESSON,
            'atom_id' => $lesson->id,
            'status' => UserEntitlementGrant::STATUS_ACTIVE,
            'source_type' => UserEntitlementGrant::SOURCE_TEST_FIXTURE,
            'source_id' => "assessment-asset-{$lesson->id}",
            'idempotency_key' => "assessment-asset-{$user->id}-{$lesson->id}",
            'granted_at' => now(),
        ]);
    }

    private function assessmentUrl(Subject $subject, Course $course, Lesson $lesson): string
    {
        return "/api/v1/learning/subjects/{$subject->slug}/courses/{$course->slug}/lessons/{$lesson->slug}/assessment";
    }

    private function assetUrl(Subject $subject, Course $course, Lesson $lesson, string $asset): string
    {
        return $this->assessmentUrl($subject, $course, $lesson)."/assets/{$asset}";
    }

    private function accessDenied(): array
    {
        return ['error' => ['code' => 'LEARNING_ACCESS_DENIED', 'message' => 'У пользователя нет доступа к этому уроку.']];
    }

    private function assetNotFound(): array
    {
        return ['error' => ['code' => 'LEARNING_ASSESSMENT_ASSET_NOT_FOUND', 'message' => 'Схема для экзамена не найдена.']];
    }
}
