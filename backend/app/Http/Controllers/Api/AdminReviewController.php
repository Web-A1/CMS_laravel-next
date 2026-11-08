<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AdminReviewException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AdminReviewRequest;
use App\Http\Resources\AdminReviewResource;
use App\Models\Course;
use App\Models\Subject;
use App\Services\Reviews\AdminReviewDeletion;
use App\Services\Reviews\AdminReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AdminReviewController extends Controller
{
    public function index(AdminReviewRequest $request, AdminReviewService $reviews): JsonResponse
    {
        $filters = $request->validated();
        $query = $reviews->filteredQuery($filters);
        $page = $query->paginate((int) ($filters['perPage'] ?? 20), ['*'], 'page', (int) ($filters['page'] ?? 1));

        return $this->respond([
            'data' => AdminReviewResource::collection($page->getCollection())->resolve($request),
            'meta' => ['currentPage' => $page->currentPage(), 'lastPage' => $page->lastPage(), 'perPage' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function options(): JsonResponse
    {
        return $this->respond(['data' => [
            'subjects' => Subject::query()->orderBy('title')->get(['id', 'title'])->toArray(),
            'courses' => Course::query()->orderBy('title')->get(['id', 'title', 'subject_id'])
                ->map(fn (Course $course) => ['id' => $course->id, 'title' => $course->title, 'subjectId' => $course->subject_id])->all(),
        ]]);
    }

    public function show(Request $request, string $review, AdminReviewService $reviews): JsonResponse
    {
        return $this->respond(['data' => (new AdminReviewResource($reviews->detail($this->id($review))))->resolve($request)]);
    }

    public function store(AdminReviewRequest $request, AdminReviewService $reviews): JsonResponse
    {
        return $this->mutate($request, $reviews, 'create', null, 201);
    }

    public function update(AdminReviewRequest $request, string $review, AdminReviewService $reviews): JsonResponse
    {
        return $this->mutate($request, $reviews, 'update', $this->id($review));
    }

    public function publication(AdminReviewRequest $request, string $review, AdminReviewService $reviews): JsonResponse
    {
        return $this->mutate($request, $reviews, 'publication', $this->id($review));
    }

    public function destroy(AdminReviewRequest $request, string $review, AdminReviewDeletion $deletion): JsonResponse
    {
        return $this->respond(['data' => $deletion->delete($request->user(), $this->id($review), $request->validated(), (string) $request->idempotencyKey())]);
    }

    private function mutate(AdminReviewRequest $request, AdminReviewService $reviews, string $operation, ?int $id, int $status = 200): JsonResponse
    {
        $review = $reviews->execute($request->user(), $operation, $id, $request->validated(), (string) $request->idempotencyKey());

        return $this->respond(['data' => (new AdminReviewResource($review))->resolve($request)], $status);
    }

    private function id(string $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new AdminReviewException('ADMIN_REVIEW_NOT_FOUND', 'Отзыв не найден.', 404);
        }

        return $id;
    }

    private function respond(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'private, no-store');
    }
}
