<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Subject;
use App\Models\UserEntitlementGrant;
use App\Services\AccessResolver;
use App\Services\Learning\LessonAssessmentProgressService;
use App\Services\Learning\LessonBodyContract;
use App\Support\ImpersonationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class LearningController extends Controller
{
    private const RESERVED_SUBJECT_SLUGS = ['lessons', 'favorites'];

    public function index(Request $request, AccessResolver $accessResolver): JsonResponse
    {
        $user = ImpersonationContext::effectiveUser($request);
        $subjects = Subject::query()
            ->active()
            ->whereNotIn('slug', self::RESERVED_SUBJECT_SLUGS)
            ->with([
                'courses' => fn ($query) => $query
                    ->active()
                    ->select(['id', 'subject_id'])
                    ->with('activeSellableEntity')
                    ->with(['lessons' => fn ($lessonQuery) => $lessonQuery
                        ->active()
                        ->select(['id', 'course_id', 'is_free_preview']),
                    ]),
            ])
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get(['id', 'slug', 'title', 'sort_order']);

        $requiredLessonIdsBySubject = [];
        $allLessonAtoms = [];

        foreach ($subjects as $subject) {
            $requiredLessonIds = [];

            foreach ($subject->courses as $course) {
                foreach ($course->lessons as $lesson) {
                    $lessonId = (int) $lesson->id;
                    $requiredLessonIds[] = $lessonId;
                    $allLessonAtoms[$lessonId] = [
                        'kind' => UserEntitlementGrant::ATOM_LESSON,
                        'id' => $lessonId,
                    ];
                }
            }

            if ($requiredLessonIds !== []) {
                $requiredLessonIdsBySubject[(int) $subject->id] = $requiredLessonIds;
            }
        }

        $ownedLessonMap = [];

        if ($allLessonAtoms !== []) {
            $indexAccess = $accessResolver->resultForAtoms(
                (int) $user->id,
                AccessResolver::ENTITY_SUBJECT,
                null,
                array_values($allLessonAtoms),
            );

            foreach ($indexAccess['owned_atoms'] as $ownedAtom) {
                $ownedLessonMap[(int) $ownedAtom['id']] = true;
            }
        }

        $subjectSummaries = [];

        foreach ($subjects as $subject) {
            $requiredLessonIds = $requiredLessonIdsBySubject[(int) $subject->id] ?? [];
            $requiredLessonsCount = count($requiredLessonIds);
            $ownedLessonsCount = count(array_filter(
                $requiredLessonIds,
                fn (int $lessonId): bool => isset($ownedLessonMap[$lessonId]),
            ));
            $sellableCoursesCount = 0;
            $boughtCoursesCount = 0;
            $freeLessonAvailable = false;

            foreach ($subject->courses as $course) {
                $lessonIds = [];

                foreach ($course->lessons as $lesson) {
                    $lessonId = (int) $lesson->id;
                    $lessonIds[] = $lessonId;
                    $freeLessonAvailable = $freeLessonAvailable || (bool) $lesson->is_free_preview;
                }

                if ($lessonIds === []) {
                    continue;
                }

                if ($course->activeSellableEntity) {
                    $sellableCoursesCount++;
                }

                $courseIsBought = count(array_filter(
                    $lessonIds,
                    fn (int $lessonId): bool => isset($ownedLessonMap[$lessonId]),
                )) === count($lessonIds);

                if ($courseIsBought) {
                    $boughtCoursesCount++;
                }
            }

            $subjectSummaries[] = [
                'id' => $subject->id,
                'slug' => $subject->slug,
                'title' => $subject->title,
                'sortOrder' => $subject->sort_order,
                'access' => match (true) {
                    $ownedLessonsCount === 0 => AccessResolver::ACCESS_NONE,
                    $ownedLessonsCount === $requiredLessonsCount => AccessResolver::ACCESS_FULL,
                    default => AccessResolver::ACCESS_PARTIAL,
                },
                'requiredLessonsCount' => $requiredLessonsCount,
                'ownedLessonsCount' => $ownedLessonsCount,
                'sellableCoursesCount' => $sellableCoursesCount,
                'boughtCoursesCount' => $boughtCoursesCount,
                'freeLessonAvailable' => $freeLessonAvailable,
                'canonicalPath' => "/lk/learning/{$subject->slug}",
            ];
        }

        usort($subjectSummaries, fn (array $first, array $second): int => [
            $first['boughtCoursesCount'] > 0 ? 0 : 1,
            $first['sortOrder'],
            mb_strtolower((string) $first['title']),
        ] <=> [
            $second['boughtCoursesCount'] > 0 ? 0 : 1,
            $second['sortOrder'],
            mb_strtolower((string) $second['title']),
        ]);

        return response()->json([
            'data' => [
                'subjects' => $subjectSummaries,
                'empty' => $subjectSummaries === [],
            ],
        ]);
    }

    public function showSubject(
        Request $request,
        Subject $subject,
        AccessResolver $accessResolver,
        LessonAssessmentProgressService $assessmentProgress,
    ): JsonResponse {
        $user = ImpersonationContext::effectiveUser($request);
        if ($this->subjectIsUnavailable($subject)) {
            return response()->json([
                'error' => [
                    'code' => 'LEARNING_SUBJECT_NOT_FOUND',
                    'message' => 'Учебный раздел не найден.',
                ],
            ], Response::HTTP_NOT_FOUND);
        }

        $subject->load([
            'courses' => fn ($query) => $query
                ->active()
                ->with(['lessons' => fn ($lessonQuery) => $lessonQuery
                    ->active()
                    ->orderBy('sort_order')
                    ->orderBy('title'),
                ])
                ->orderBy('sort_order')
                ->orderBy('title'),
        ]);

        $access = $accessResolver->check(
            $user,
            AccessResolver::ENTITY_SUBJECT,
            (int) $subject->id,
        );
        $ownedLessonMap = $this->ownedLessonMap($access['owned_atoms']);
        $progressByLesson = $assessmentProgress->forLessons(
            (int) $user->id,
            $subject->courses
                ->flatMap(fn (Course $course) => $course->lessons)
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all(),
        );
        $courses = [];

        foreach ($subject->courses as $course) {
            $requiredLessonsCount = $course->lessons->count();
            $ownedLessonsCount = $course->lessons
                ->filter(fn (Lesson $lesson): bool => isset($ownedLessonMap[(int) $lesson->id]))
                ->count();

            $courseData = $this->coursePayload($subject, $course);
            $courseData['access'] = match (true) {
                $ownedLessonsCount === 0 => AccessResolver::ACCESS_NONE,
                $ownedLessonsCount === $requiredLessonsCount => AccessResolver::ACCESS_FULL,
                default => AccessResolver::ACCESS_PARTIAL,
            };
            $courseData['requiredLessonsCount'] = $requiredLessonsCount;
            $courseData['ownedLessonsCount'] = $ownedLessonsCount;
            $courseData['completedLessonsCount'] = $this->completedLessonsCount(
                $course->lessons,
                $progressByLesson,
            );
            $courses[] = $courseData;
        }

        usort($courses, fn (array $first, array $second): int => [
            $first['access'] === AccessResolver::ACCESS_FULL ? 0 : 1,
            $first['sortOrder'],
            mb_strtolower((string) $first['title']),
        ] <=> [
            $second['access'] === AccessResolver::ACCESS_FULL ? 0 : 1,
            $second['sortOrder'],
            mb_strtolower((string) $second['title']),
        ]);

        return response()->json([
            'data' => [
                'subject' => $this->subjectPayload($subject),
                'access' => $access['access'],
                'requiredLessonsCount' => $access['required_atoms_count'],
                'ownedLessonsCount' => $access['owned_atoms_count'],
                'courses' => $courses,
                'empty' => $courses === [],
            ],
        ]);
    }

    public function showCourse(
        Request $request,
        Subject $subject,
        Course $course,
        AccessResolver $accessResolver,
        LessonAssessmentProgressService $assessmentProgress,
    ): JsonResponse {
        $user = ImpersonationContext::effectiveUser($request);
        if (
            $this->subjectIsUnavailable($subject)
            || ! $course->is_active
            || (int) $course->subject_id !== (int) $subject->id
        ) {
            return response()->json([
                'error' => [
                    'code' => 'LEARNING_COURSE_NOT_FOUND',
                    'message' => 'Учебный курс не найден.',
                ],
            ], Response::HTTP_NOT_FOUND);
        }

        $course->load(['lessons' => fn ($query) => $query
            ->active()
            ->orderBy('sort_order')
            ->orderBy('title'),
        ]);

        $access = $accessResolver->check(
            $user,
            AccessResolver::ENTITY_COURSE,
            (int) $course->id,
        );
        $ownedLessonMap = $this->ownedLessonMap($access['owned_atoms']);
        $progressByLesson = $assessmentProgress->forLessons(
            (int) $user->id,
            $course->lessons->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(),
        );
        $lessons = $course->lessons
            ->map(function (Lesson $lesson) use ($course, $ownedLessonMap, $progressByLesson, $subject): array {
                $isEntitled = isset($ownedLessonMap[(int) $lesson->id]);
                $isFreePreview = (bool) $lesson->is_free_preview;
                $assessment = $progressByLesson[(int) $lesson->id] ?? $this->unavailableAssessmentProgress();

                return [
                    'id' => $lesson->id,
                    'slug' => $lesson->slug,
                    'title' => $lesson->title,
                    'sortOrder' => $lesson->sort_order,
                    'access' => $isEntitled || $isFreePreview
                        ? AccessResolver::ACCESS_FULL
                        : AccessResolver::ACCESS_NONE,
                    'availability' => match (true) {
                        $isEntitled => 'entitled',
                        $isFreePreview => 'free_preview',
                        default => 'locked',
                    },
                    'canonicalPath' => "/lk/learning/{$subject->slug}/{$course->slug}/{$lesson->slug}",
                    ...$assessment,
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'subject' => $this->subjectPayload($subject),
                'course' => $this->coursePayload($subject, $course),
                'access' => $access['access'],
                'requiredLessonsCount' => $access['required_atoms_count'],
                'ownedLessonsCount' => $access['owned_atoms_count'],
                'completedLessonsCount' => $this->completedLessonsCount($course->lessons, $progressByLesson),
                'lessons' => $lessons,
                'empty' => $lessons === [],
            ],
        ]);
    }

    public function showLesson(
        Request $request,
        Subject $subject,
        Course $course,
        Lesson $lesson,
        AccessResolver $accessResolver,
        LessonBodyContract $lessonBodyContract,
        LessonAssessmentProgressService $assessmentProgress,
    ): JsonResponse {
        $user = ImpersonationContext::effectiveUser($request);
        if (
            $this->subjectIsUnavailable($subject)
            || ! $course->is_active
            || ! $lesson->is_active
            || (int) $course->subject_id !== (int) $subject->id
            || (int) $lesson->course_id !== (int) $course->id
        ) {
            return response()->json([
                'error' => [
                    'code' => 'LEARNING_LESSON_NOT_FOUND',
                    'message' => 'Урок не найден.',
                ],
            ], Response::HTTP_NOT_FOUND);
        }

        $access = $accessResolver->check(
            $user,
            AccessResolver::ENTITY_LESSON,
            (int) $lesson->id,
        );

        if (! $this->lessonIsAccessible($access['access'], $lesson)) {
            return response()->json([
                'error' => [
                    'code' => 'LEARNING_ACCESS_DENIED',
                    'message' => 'У пользователя нет доступа к этому уроку.',
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        $content = $lessonBodyContract->toStudentPayload($lesson->learningContent()->first()) ?? [
            'status' => 'metadata_only',
        ];
        $assessment = $assessmentProgress->forLessons((int) $user->id, [(int) $lesson->id])[(int) $lesson->id]
            ?? $this->unavailableAssessmentProgress();

        return response()->json([
            'data' => [
                'id' => $lesson->id,
                'slug' => $lesson->slug,
                'title' => $lesson->title,
                'sortOrder' => $lesson->sort_order,
                'access' => AccessResolver::ACCESS_FULL,
                'canonicalPath' => "/lk/learning/{$subject->slug}/{$course->slug}/{$lesson->slug}",
                'subject' => $this->subjectPayload($subject),
                'course' => $this->coursePayload($subject, $course),
                'content' => $content,
                ...$assessment,
            ],
        ]);
    }

    public function showLessonMaterials(
        Request $request,
        Subject $subject,
        Course $course,
        Lesson $lesson,
        AccessResolver $accessResolver,
    ): JsonResponse {
        if (ImpersonationContext::isActive($request)) {
            return ImpersonationContext::mediaDisabledResponse();
        }

        $user = ImpersonationContext::effectiveUser($request);
        if (
            $this->subjectIsUnavailable($subject)
            || ! $course->is_active
            || ! $lesson->is_active
        ) {
            return response()->json([
                'error' => [
                    'code' => 'LEARNING_LESSON_NOT_FOUND',
                    'message' => 'Урок не найден.',
                ],
            ], Response::HTTP_NOT_FOUND);
        }

        $access = $accessResolver->check(
            $user,
            AccessResolver::ENTITY_LESSON,
            (int) $lesson->id,
        );

        if (! $this->lessonIsAccessible($access['access'], $lesson)) {
            return response()->json([
                'error' => [
                    'code' => 'LEARNING_ACCESS_DENIED',
                    'message' => 'У пользователя нет доступа к этому уроку.',
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        $materials = $lesson->materials()
            ->published()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['kind', 'title'])
            ->map(fn ($material): array => [
                'kind' => $material->kind,
                'title' => $material->title,
            ])
            ->all();

        return response()->json([
            'data' => [
                'materials' => $materials,
            ],
        ]);
    }

    private function subjectIsUnavailable(Subject $subject): bool
    {
        return ! $subject->is_active
            || in_array($subject->slug, self::RESERVED_SUBJECT_SLUGS, true);
    }

    private function lessonIsAccessible(string $access, Lesson $lesson): bool
    {
        return $access === AccessResolver::ACCESS_FULL || (bool) $lesson->is_free_preview;
    }

    /**
     * @param  array<int, array{kind:string,id:int}>  $ownedAtoms
     * @return array<int, true>
     */
    private function ownedLessonMap(array $ownedAtoms): array
    {
        return collect($ownedAtoms)
            ->filter(fn (array $atom): bool => $atom['kind'] === UserEntitlementGrant::ATOM_LESSON)
            ->mapWithKeys(fn (array $atom): array => [(int) $atom['id'] => true])
            ->all();
    }

    /**
     * @return array{id:int,slug:string,title:string,canonicalPath:string}
     */
    private function subjectPayload(Subject $subject): array
    {
        return [
            'id' => $subject->id,
            'slug' => $subject->slug,
            'title' => $subject->title,
            'canonicalPath' => "/lk/learning/{$subject->slug}",
        ];
    }

    /**
     * @return array{id:int,itemId:string,slug:string,title:string,shortTitle:?string,sortOrder:int,canonicalPath:string,publicPath:string,freeLessonAvailable:bool}
     */
    private function coursePayload(Subject $subject, Course $course): array
    {
        return [
            'id' => $course->id,
            'itemId' => "course:{$subject->slug}:{$course->slug}",
            'slug' => $course->slug,
            'title' => $course->title,
            'shortTitle' => $course->short_title,
            'sortOrder' => $course->sort_order,
            'canonicalPath' => "/lk/learning/{$subject->slug}/{$course->slug}",
            'publicPath' => "/subjects/{$subject->slug}/{$course->slug}",
            'freeLessonAvailable' => $this->courseHasFreeLesson($course),
        ];
    }

    private function courseHasFreeLesson(Course $course): bool
    {
        if ($course->relationLoaded('lessons')) {
            return $course->lessons->contains(
                fn (Lesson $lesson): bool => (bool) $lesson->is_free_preview,
            );
        }

        return $course->lessons()
            ->active()
            ->where('is_free_preview', true)
            ->exists();
    }

    /**
     * @param  Collection<int, Lesson>  $lessons
     * @param  array<int, array{testAvailable:bool,testPassed:bool,testProgress:?array{status:string,answeredCount:int,correctCount:int,totalCount:int,completedAt:?string,retakeAvailable:bool,retakeInProgress:bool}}>  $progressByLesson
     */
    private function completedLessonsCount(Collection $lessons, array $progressByLesson): int
    {
        return $lessons->filter(
            fn (Lesson $lesson): bool => ($progressByLesson[(int) $lesson->id]['testPassed'] ?? false) === true,
        )->count();
    }

    /** @return array{testAvailable:bool,testPassed:bool,testProgress:null} */
    private function unavailableAssessmentProgress(): array
    {
        return [
            'testAvailable' => false,
            'testPassed' => false,
            'testProgress' => null,
        ];
    }
}
