<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\AssessmentReviewDetail;
use App\Filament\Pages\AssessmentReviews;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAssessment;
use App\Models\LessonAssessmentVersion;
use App\Models\Subject;
use App\Models\User;
use App\Services\Learning\LessonAssessmentAttemptService;
use App\Services\Learning\LessonAssessmentManualReviewService;
use Database\Seeders\LessonAssessmentSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class AdminAssessmentPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_queue_and_detail_apply_role_and_student_scope(): void
    {
        foreach (['manager', 'teacher'] as $role) {
            $actor = User::factory()->create(['role' => $role]);
            [$assigned, $student] = $this->pendingAttempt($actor->id);
            [$unassigned, $hidden] = $this->pendingAttempt();
            Livewire::actingAs($actor, 'web')->test(AssessmentReviews::class)->assertSee($student->email)->assertDontSee($hidden->email);
            $this->actingAs($actor, 'web')->get('/admin/assessment-reviews/'.$assigned->id)->assertOk()->assertSee('Student answer');
            $this->get('/admin/assessment-reviews/'.$unassigned->id)->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'admin_limited']), 'web')->get('/admin/assessment-reviews')->assertForbidden();
    }

    public function test_reassignment_after_mount_denies_read_and_write_without_events(): void
    {
        $actor = User::factory()->create(['role' => 'manager']);
        [$attempt, $student] = $this->pendingAttempt($actor->id);
        $review = $attempt->manualReviews()->firstOrFail();
        $page = Livewire::actingAs($actor, 'web')->test(AssessmentReviewDetail::class, ['attempt' => (string) $attempt->id]);
        $student->update(['manager_id' => null]);
        $before = DB::table('lesson_assessment_review_events')->count();
        $page->call('saveItem', $review->id)->assertForbidden();
        $this->assertSame($before, DB::table('lesson_assessment_review_events')->count());
        $this->assertNull($review->fresh()->score_bps);
    }

    public function test_stale_revision_and_reused_key_conflict_preserve_existing_grade(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        [$attempt] = $this->pendingAttempt();
        $review = $attempt->manualReviews()->firstOrFail();
        $page = Livewire::actingAs($actor, 'web')->test(AssessmentReviewDetail::class, ['attempt' => (string) $attempt->id]);
        app(LessonAssessmentManualReviewService::class)->update($actor, $attempt->id, $review->id, 0, 8000, null, 'concurrent-editor');
        $page->set('drafts.'.$review->id.'.scorePercent', '90')->call('saveItem', $review->id)->assertHasErrors('review');
        $this->assertSame(8000, $review->fresh()->score_bps);
        $page->call('loadEditor')->set('drafts.'.$review->id.'.scorePercent', '100')->call('saveItem', $review->id)->assertHasNoErrors();
        $before = DB::table('lesson_assessment_review_events')->count();
        $page->call('saveItem', $review->id)->assertHasNoErrors();
        $this->assertSame($before, DB::table('lesson_assessment_review_events')->count());
        $key = $page->get('submissions')[$review->id]['key'];
        $page->set('drafts.'.$review->id.'.scorePercent', '100.00')->call('saveItem', $review->id)->assertHasNoErrors();
        $this->assertSame($key, $page->get('submissions')[$review->id]['key']);
        $page->set('drafts.'.$review->id.'.scorePercent', '70')->call('saveItem', $review->id)->assertHasNoErrors();
        $this->assertNotSame($key, $page->get('submissions')[$review->id]['key']);
        $this->assertSame(7000, $review->fresh()->score_bps);
    }

    public function test_finalize_requires_all_answers_and_captured_revision_then_preserves_terminal_state(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        [$attempt] = $this->pendingAttempt();
        $review = $attempt->manualReviews()->firstOrFail();
        $page = Livewire::actingAs($actor, 'web')->test(AssessmentReviewDetail::class, ['attempt' => (string) $attempt->id]);
        $page->call('finalize')->assertHasErrors('review');
        $page->set('drafts.'.$review->id.'.scorePercent', '100')->call('saveItem', $review->id)->assertHasNoErrors();
        $page->call('finalize')->assertHasNoErrors();
        $this->assertSame('passed', $attempt->fresh()->status);
        $before = DB::table('lesson_assessment_review_events')->count();
        $page->call('finalize')->assertHasNoErrors();
        $this->assertSame($before, DB::table('lesson_assessment_review_events')->count());
    }

    public function test_permission_revocation_after_mount_is_denied(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        [$attempt] = $this->pendingAttempt();
        $page = Livewire::actingAs($actor, 'web')->test(AssessmentReviewDetail::class, ['attempt' => (string) $attempt->id]);
        $actor->update(['role' => 'admin_limited']);
        $page->call('loadEditor')->assertForbidden();
    }

    public function test_finalize_does_not_silently_replace_the_captured_revision(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        [$attempt] = $this->pendingAttempt();
        $review = $attempt->manualReviews()->firstOrFail();
        $page = Livewire::actingAs($actor, 'web')->test(AssessmentReviewDetail::class, ['attempt' => (string) $attempt->id]);
        app(LessonAssessmentManualReviewService::class)->update($actor, $attempt->id, $review->id, 0, 10000, null, 'another-reviewer');
        $page->call('finalize')->assertHasErrors('review');
        $this->assertNull($attempt->fresh()->finalized_at);
        $page->call('loadEditor')->call('finalize')->assertHasNoErrors();
        $this->assertSame('passed', $attempt->fresh()->status);
    }

    public function test_score_validation_rejects_excess_and_extra_fields_then_preserves_decimal_precision(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        [$attempt] = $this->pendingAttempt();
        $review = $attempt->manualReviews()->firstOrFail();
        $page = Livewire::actingAs($actor, 'web')->test(AssessmentReviewDetail::class, ['attempt' => (string) $attempt->id]);
        $page->set('drafts.'.$review->id.'.scorePercent', '100.01')->call('saveItem', $review->id)->assertHasErrors('drafts.'.$review->id.'.scorePercent');
        $page->set('drafts.'.$review->id.'.unexpected', true)->call('saveItem', $review->id)->assertHasErrors('drafts.'.$review->id);
        $this->assertNull($review->fresh()->score_bps);
        $page->call('loadEditor')->set('drafts.'.$review->id.'.scorePercent', '87.25')->call('saveItem', $review->id)->assertHasNoErrors();
        $this->assertSame(8725, $review->fresh()->score_bps);
    }

    public function test_queue_headers_combine_search_sort_and_status_and_keep_all_after_reload(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        [$first, $student] = $this->pendingAttempt();
        [$second] = $this->pendingAttempt();
        app(LessonAssessmentManualReviewService::class)->update($actor, $first->id, $first->manualReviews()->firstOrFail()->id, 0, 10000, null, 'column-sort-fixture');
        $page = Livewire::actingAs($actor, 'web')->test(AssessmentReviews::class)
            ->call('sortColumn', 'queue', 'reviewed')
            ->assertViewHas('rows', fn ($rows) => $rows[0]['id'] === $second->id);
        $page->set('columnFilters.queue.student', $student->email)
            ->set('columnFilters.queue.context', 'Course')
            ->assertViewHas('total', 1)
            ->call('chooseColumnFilter', 'queue', 'status', 'finalized')->assertViewHas('total', 0)
            ->call('chooseColumnFilter', 'queue', 'status', '')->assertViewHas('total', 1);
        Livewire::withQueryParams(['columns' => ['queue' => ['status' => null]]])
            ->test(AssessmentReviews::class)->assertSet('columnFilters.queue.status', '');
    }

    public function test_previous_queue_search_remains_visible_and_clearable(): void
    {
        [, $student] = $this->pendingAttempt();
        $this->pendingAttempt();
        Livewire::actingAs(User::factory()->create(['role' => 'admin_full']), 'web')
            ->withQueryParams(['search' => $student->email])->test(AssessmentReviews::class)
            ->assertSee('Сбросить общий поиск')->assertViewHas('total', 1)
            ->call('clearPreviousSearch')->assertSet('search', '')->assertSet('page', 1)
            ->assertDontSee('Сбросить общий поиск')->assertViewHas('total', 2);
    }

    public function test_item_ack_preserves_sibling_draft_and_native_form_for_later_input(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        [$attempt] = $this->pendingAttempt(twoManual: true);
        [$first, $second] = $attempt->manualReviews()->orderBy('id')->get()->all();
        $page = Livewire::actingAs($actor, 'web')->test(AssessmentReviewDetail::class, ['attempt' => (string) $attempt->id]);
        $opening = $page->get('savingOpening');
        $page->set('drafts.'.$first->id, ['scorePercent' => '90', 'comment' => 'First comment'])
            ->set('drafts.'.$second->id, ['scorePercent' => '50', 'comment' => 'Unsubmitted sibling'])
            ->call('saveItem', $first->id)->assertHasNoErrors()
            ->assertSet('drafts.'.$second->id.'.comment', 'Unsubmitted sibling')->assertSet('savingOpening', $opening)
            ->assertSeeHtml('wire:submit="saveItem('.$first->id.')"')->assertSeeHtml('wire:ignore.self');
        $this->assertSame(9000, $first->fresh()->score_bps);
        $this->assertNull($second->fresh()->score_bps);
        $this->assertSame('', $page->get('savingBaseline')['drafts.'.$second->id.'.scorePercent']);
        $this->assertSame(4, substr_count($page->html(), 'data-ui="field-saving.hint"'));
        $this->assertSame('saveOrdinary', $page->instance()->savingConfig()['submitMethod']);
        $this->assertNull($attempt->fresh()->finalized_at);
    }

    public function test_ordinary_grade_save_prevalidates_all_groups_and_never_finalizes(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        [$attempt] = $this->pendingAttempt(twoManual: true);
        [$first, $second] = $attempt->manualReviews()->orderBy('id')->get()->all();
        $page = Livewire::actingAs($actor, 'web')->test(AssessmentReviewDetail::class, ['attempt' => (string) $attempt->id]);
        $submissions = $page->get('submissions');
        $page->set('drafts.'.$first->id.'.scorePercent', '90')->set('drafts.'.$second->id.'.scorePercent', '101')
            ->call('saveOrdinary')->assertHasErrors('drafts.'.$second->id.'.scorePercent')->assertSet('submissions', $submissions);
        $this->assertNull($first->fresh()->score_bps);
        $this->assertDatabaseCount('lesson_assessment_review_events', 0);
        $page->set('drafts.'.$second->id.'.scorePercent', '80')->call('saveOrdinary')->assertHasNoErrors();
        $this->assertSame(9000, $first->fresh()->score_bps);
        $this->assertSame(8000, $second->fresh()->score_bps);
        $this->assertDatabaseCount('lesson_assessment_review_events', 2);
        $this->assertNull($attempt->fresh()->finalized_at);
        $this->assertSame('pending_review', $attempt->fresh()->status);
    }

    public function test_conflicting_sibling_rolls_back_batch_and_preserves_both_drafts_and_keys(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        [$attempt] = $this->pendingAttempt(twoManual: true);
        [$first, $second] = $attempt->manualReviews()->orderBy('id')->get()->all();
        $page = Livewire::actingAs($actor, 'web')->test(AssessmentReviewDetail::class, ['attempt' => (string) $attempt->id]);
        $submissions = $page->get('submissions');
        app(LessonAssessmentManualReviewService::class)->update($actor, $attempt->id, $second->id, 0, 8000, null, 'other-sibling-review');
        $page->set('drafts.'.$first->id.'.scorePercent', '90')->set('drafts.'.$second->id.'.scorePercent', '70')
            ->call('saveOrdinary')->assertHasErrors('review')->assertSet('submissions', $submissions)
            ->assertSet('drafts.'.$first->id.'.scorePercent', '90')->assertSet('drafts.'.$second->id.'.scorePercent', '70');
        $this->assertNull($first->fresh()->score_bps);
        $this->assertSame(8000, $second->fresh()->score_bps);
        $this->assertDatabaseCount('lesson_assessment_review_events', 1);
    }

    public function test_explicit_finalize_refuses_unsaved_grade_draft_then_accepts_saved_ordinary_input(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        [$attempt] = $this->pendingAttempt();
        $review = $attempt->manualReviews()->firstOrFail();
        $page = Livewire::actingAs($actor, 'web')->test(AssessmentReviewDetail::class, ['attempt' => (string) $attempt->id]);
        $page->set('drafts.'.$review->id.'.scorePercent', '100')->call('saveItem', $review->id)->assertHasNoErrors()
            ->set('drafts.'.$review->id.'.comment', 'Unsubmitted comment')->call('finalize')->assertHasErrors('drafts');
        $this->assertNull($attempt->fresh()->finalized_at);
        $page->call('saveOrdinary')->assertHasNoErrors()->call('finalize')->assertHasNoErrors();
        $this->assertSame('passed', $attempt->fresh()->status);
    }

    public function test_item_validation_errors_survive_component_memo_and_correction_keeps_submission_key(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        [$attempt] = $this->pendingAttempt();
        $review = $attempt->manualReviews()->firstOrFail();
        $page = Livewire::actingAs($actor, 'web')->test(AssessmentReviewDetail::class, ['attempt' => (string) $attempt->id]);
        $key = $page->get('submissions')[$review->id]['key'];
        $field = 'drafts.'.$review->id.'.scorePercent';
        foreach (['1e2', '100.01'] as $invalid) {
            $page->set($field, $invalid)->call('saveItem', $review->id)->assertHasErrors($field)->assertSet($field, $invalid);
            $this->assertArrayHasKey($field, $page->snapshot['memo']['errors']);
            $this->assertNull($page->effects['returns'][0] ?? null);
            $this->assertSame($key, $page->get('submissions')[$review->id]['key']);
        }
        $page->set($field, '88')->call('saveItem', $review->id)->assertHasNoErrors();
        $this->assertSame([], $page->snapshot['memo']['errors']);
        $this->assertSame(8800, $review->fresh()->score_bps);
        $this->assertDatabaseCount('lesson_assessment_review_events', 1);
    }

    public function test_item_conflict_returns_explicit_terminal_ack_even_without_nonproperty_memo_error(): void
    {
        $actor = User::factory()->create(['role' => 'admin_full']);
        [$attempt] = $this->pendingAttempt();
        $review = $attempt->manualReviews()->firstOrFail();
        $page = Livewire::actingAs($actor, 'web')->test(AssessmentReviewDetail::class, ['attempt' => (string) $attempt->id]);
        app(LessonAssessmentManualReviewService::class)->update($actor, $attempt->id, $review->id, 0, 8000, null, 'memo-conflict-writer');
        $page->set('drafts.'.$review->id.'.scorePercent', '90')->call('saveItem', $review->id)->assertHasErrors('review');
        $ack = $page->effects['returns'][0];
        $this->assertFalse($ack['ok']);
        $this->assertTrue($ack['terminal']);
        $this->assertStringContainsString('The assessment review has changed.', $ack['message']);
        $this->assertSame([], $page->snapshot['memo']['errors']);
        $this->assertSame(8000, $review->fresh()->score_bps);
    }

    private function pendingAttempt(?int $managerId = null, bool $twoManual = false): array
    {
        $subject = Subject::query()->create([
            'slug' => 'admin-review-subject-'.uniqid(),
            'title' => 'Subject',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $course = Course::query()->create([
            'subject_id' => $subject->id,
            'slug' => 'admin-review-course-'.uniqid(),
            'title' => 'Course',
            'price_cents' => 1000,
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $lesson = Lesson::query()->create([
            'course_id' => $course->id,
            'slug' => 'admin-review-lesson-'.uniqid(),
            'title' => 'Lesson',
            'sort_order' => 1,
            'is_active' => true,
        ]);
        $assessment = LessonAssessment::query()->create(['lesson_id' => $lesson->id]);
        LessonAssessmentVersion::query()->forceCreate([
            'lesson_assessment_id' => $assessment->id,
            'status' => LessonAssessmentVersion::STATUS_PUBLISHED,
            'schema_version' => 2,
            'content_version' => 37,
            'scoring_policy' => LessonAssessmentSeeder::validatedFixture()['scoringPolicy'],
            'source_assignment_sha256' => str_repeat('a', 64),
            'source_key_sha256' => str_repeat('b', 64),
            'sections' => [
                ['id' => 'automatic', 'title' => 'Automatic'],
                ['id' => 'manual', 'title' => 'Manual'],
            ],
            'questions' => [
                [
                    'id' => 'automatic-01',
                    'sectionId' => 'automatic',
                    'type' => 'single_choice',
                    'gradingMode' => 'automatic',
                    'prompt' => 'Automatic prompt',
                    'options' => [['id' => 'right', 'label' => 'Right']],
                ],
                [
                    'id' => 'manual-01',
                    'sectionId' => 'manual',
                    'type' => 'long_text',
                    'gradingMode' => 'manual',
                    'prompt' => 'Manual prompt',
                    'rows' => 4,
                ],
                ...($twoManual ? [['id' => 'manual-02', 'sectionId' => 'manual', 'type' => 'long_text', 'gradingMode' => 'manual', 'prompt' => 'Second manual prompt', 'rows' => 4]] : []),
            ],
            'answer_key' => [
                'automatic-01' => ['response' => ['selectedOptionId' => 'right']],
                'manual-01' => ['response' => ['referenceAnswers' => ['Expected answer']]],
                ...($twoManual ? ['manual-02' => ['response' => ['referenceAnswers' => ['Second expected answer']]]] : []),
            ],
            'published_at' => now(),
        ]);
        $student = User::factory()->create(['manager_id' => $managerId]);
        $attempts = app(LessonAssessmentAttemptService::class);
        $attempt = $attempts->start($student, $assessment)['attempt'];
        $attempt = $attempts->save($student, $attempt, 0, [
            ['questionId' => 'automatic-01', 'response' => ['selectedOptionId' => 'right']],
            ['questionId' => 'manual-01', 'response' => ['text' => 'Student answer']],
            ...($twoManual ? [['questionId' => 'manual-02', 'response' => ['text' => 'Second student answer']]] : []),
        ], 'manual-01');
        $attempt = $attempts->submit($student, $attempt, 1)['attempt'];

        return [$attempt, $student];
    }
}
