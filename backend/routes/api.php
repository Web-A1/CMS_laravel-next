<?php

use App\Http\Controllers\Api\AccessController;
use App\Http\Controllers\Api\AccountProfileController;
use App\Http\Controllers\Api\AdminAssessmentReviewController;
use App\Http\Controllers\Api\AdminFinancialReportController;
use App\Http\Controllers\Api\AdminImpersonationController;
use App\Http\Controllers\Api\AdminLegacyUserController;
use App\Http\Controllers\Api\AdminOperationsController;
use App\Http\Controllers\Api\AdminReviewController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AdminUserManagementController;
use App\Http\Controllers\Api\ArticleController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\ContentAuthorController;
use App\Http\Controllers\Api\FaqController;
use App\Http\Controllers\Api\GuideController;
use App\Http\Controllers\Api\GuideFileController;
use App\Http\Controllers\Api\KinescopeAuthorizationController;
use App\Http\Controllers\Api\LearningAssessmentAssetController;
use App\Http\Controllers\Api\LearningAssessmentController;
use App\Http\Controllers\Api\LearningController;
use App\Http\Controllers\Api\LearningFileController;
use App\Http\Controllers\Api\LearningVideoController;
use App\Http\Controllers\Api\LegacyActivationController;
use App\Http\Controllers\Api\PaymentCoinsController;
use App\Http\Controllers\Api\PaymentHistoryController;
use App\Http\Controllers\Api\PaymentPromosController;
use App\Http\Controllers\Api\PaymentsController;
use App\Http\Controllers\Api\PodeliWebhookController;
use App\Http\Controllers\Api\PublicPageController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\RobokassaWebhookController;
use App\Http\Controllers\Api\SupportTicketController;
use App\Http\Controllers\Api\TrustedBrowserAdminController;
use App\Http\Controllers\Api\TrustedBrowserChallengeController;
use App\Http\Controllers\Api\TrustedBrowserController;
use App\Http\Controllers\Api\VideoWatermarkAdminController;
use App\Http\Controllers\Api\YandexSplitWebhookController;
use App\Http\Middleware\EnsureTrustedBrowserRequestOrigin;
use App\Http\Middleware\SupportSubmissionGuard;
use Illuminate\Support\Facades\Route;

Route::get('/v1/content-authors', [ContentAuthorController::class, 'index'])->middleware('throttle:120,1,content-authors');

Route::prefix('v1/auth')->group(function (): void {
    Route::prefix('legacy-activation')->middleware('throttle:20,1,legacy-activation')->group(function (): void {
        Route::post('/email/start', [LegacyActivationController::class, 'start'])->middleware('throttle:5,1,legacy-activation-start');
        Route::post('/email/verify', [LegacyActivationController::class, 'verifyEmail']);
        Route::post('/password', [LegacyActivationController::class, 'password']);
        Route::post('/phone/start', [LegacyActivationController::class, 'startPhone']);
        Route::post('/status', [LegacyActivationController::class, 'status']);
        Route::post('/complete', [LegacyActivationController::class, 'complete']);
    });
    Route::post('/legacy-activation/phone/callback/redsms', [LegacyActivationController::class, 'callback'])
        ->middleware('throttle:120,1,legacy-activation-callback');
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware('throttle:20,1');
    Route::post('/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1');
    Route::post('/preverify/email/start', [AuthController::class, 'startPreRegistrationEmailVerification'])
        ->middleware('throttle:20,1');
    Route::post('/preverify/email/verify', [AuthController::class, 'verifyPreRegistrationEmailCode'])
        ->middleware('throttle:20,1');
    Route::post('/preverify/phone/start', [AuthController::class, 'startPreRegistrationPhoneVerification'])
        ->middleware('throttle:20,1');
    Route::get('/preverify/phone/status', [AuthController::class, 'preRegistrationPhoneVerificationStatus'])
        ->middleware('throttle:60,1');
    Route::post('/preverify/phone/callback/redsms', [AuthController::class, 'preRegistrationPhoneVerificationCallback']);
    Route::post('/password/forgot', [AuthController::class, 'forgotPassword'])
        ->middleware('throttle:10,1');
    Route::post('/password/reset', [AuthController::class, 'resetPassword'])
        ->middleware('throttle:10,1');
    Route::post('/phone-verification/callback/redsms', [AuthController::class, 'phoneVerificationCallback']);

});

Route::post('/v1/payments/webhooks/yandex_split', YandexSplitWebhookController::class)
    ->middleware('throttle:120,1');
Route::post('/v1/payments/webhooks/robokassa', RobokassaWebhookController::class)
    ->middleware('throttle:120,1');
Route::post('/v1/payments/webhooks/podeli', PodeliWebhookController::class)
    ->middleware('throttle:120,1');
Route::post('/v1/payments/webhooks/{provider}', [PaymentsController::class, 'webhook'])
    ->middleware('throttle:120,1');
Route::post('/v1/payments/webhooks/cloudpayments/{type}', [PaymentsController::class, 'cloudpaymentsWebhook'])
    ->middleware('throttle:120,1');
Route::post('/v1/integrations/kinescope/authorize', KinescopeAuthorizationController::class)
    ->middleware('throttle:kinescope-callback');

Route::prefix('v1')->group(function (): void {
    Route::post('/support-tickets', [SupportTicketController::class, 'store'])->middleware(SupportSubmissionGuard::class);
    Route::get('/articles', [ArticleController::class, 'index']);
    Route::get('/article-subjects', [ArticleController::class, 'subjects']);
    // Reserved routes precede public article slugs.
    Route::get('/articles/categories', [ArticleController::class, 'categories']);
    Route::get('/articles/redirect', [ArticleController::class, 'redirect']);
    Route::get('/articles/{slug}', [ArticleController::class, 'show']);
    Route::get('/catalog/subjects', [CatalogController::class, 'subjects']);
    Route::get('/catalog/sellables', [CatalogController::class, 'sellables']);
    Route::get('/faqs', [FaqController::class, 'index']);
    Route::get('/pages/about', [PublicPageController::class, 'about']);
    Route::get('/reviews', [ReviewController::class, 'index']);
    Route::get('/reviews/{review}', [ReviewController::class, 'show']);
});

Route::middleware('auth:sanctum')->prefix('v1')->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::patch('/me/profile', AccountProfileController::class)->middleware('throttle:30,1');
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/auth/idle-logout', [AuthController::class, 'idleLogout']);
    Route::get('/impersonation', [AdminImpersonationController::class, 'current']);
    Route::post('/impersonation/stop', [AdminImpersonationController::class, 'stop'])
        ->middleware('throttle:30,1');
    Route::get('/onboarding/status', [AuthController::class, 'onboardingStatus']);
    Route::post('/auth/phone-verification/start', [AuthController::class, 'startPhoneVerification'])
        ->middleware('throttle:10,1');
    Route::get('/auth/phone-verification/status', [AuthController::class, 'phoneVerificationStatus']);
    Route::post('/auth/email-verification/start', [AuthController::class, 'startEmailVerification'])
        ->middleware('throttle:10,1');
    Route::post('/auth/email-verification/verify', [AuthController::class, 'verifyEmailVerificationCode'])
        ->middleware('throttle:10,1');
    Route::get('/auth/device-trust/status', [TrustedBrowserController::class, 'status']);
    Route::post('/auth/device-trust/challenges', [TrustedBrowserChallengeController::class, 'store'])
        ->middleware([EnsureTrustedBrowserRequestOrigin::class, 'throttle:30,1']);
    Route::post('/auth/device-trust/challenges/{challenge}/verify', [TrustedBrowserChallengeController::class, 'verify'])
        ->middleware([EnsureTrustedBrowserRequestOrigin::class, 'throttle:60,1']);
    Route::get('/auth/trusted-browsers', [TrustedBrowserController::class, 'index']);
    Route::post('/auth/trusted-browsers/{slot}/revoke', [TrustedBrowserController::class, 'revoke'])
        ->whereNumber('slot')
        ->middleware([EnsureTrustedBrowserRequestOrigin::class, 'throttle:30,1']);
    Route::get('/lk/dashboard', fn () => response()->json(['status' => 'ok']));

    Route::prefix('access')->group(function (): void {
        Route::post('/check', [AccessController::class, 'check'])
            ->middleware('permission:access.read');
        Route::post('/grants', [AccessController::class, 'grant'])
            ->middleware('permission:access.grant');
    });

    Route::prefix('learning')
        ->middleware('permission:access.read')
        ->group(function (): void {
            Route::get('/guides', [GuideController::class, 'index']);
            Route::prefix('guides/{guide:slug}')->group(function (): void {
                $missing = fn () => response()->json(['error' => [
                    'code' => 'LEARNING_GUIDE_NOT_FOUND', 'message' => 'Методичка не найдена.',
                ]], 404)->header('Cache-Control', 'private, no-store');
                $controller = GuideFileController::class;
                Route::get('/file-materials', [$controller, 'index'])->missing($missing);
                Route::post('/file-deliveries', [$controller, 'store'])->middleware('throttle:20,1')->missing($missing);
                Route::post('/file-deliveries/prewarm', [$controller, 'prewarm'])->middleware('throttle:10,1')->missing($missing);
                Route::get('/file-deliveries/{delivery}', [$controller, 'show'])->middleware('throttle:60,1')->missing($missing);
                Route::post('/file-deliveries/{delivery}/access-grants', [$controller, 'grantAccess'])->middleware('throttle:30,1')->missing($missing);
            });
            Route::get('/', [LearningController::class, 'index']);
            Route::get('/subjects/{subject:slug}', [LearningController::class, 'showSubject'])
                ->scopeBindings()
                ->missing(fn () => response()->json([
                    'error' => [
                        'code' => 'LEARNING_SUBJECT_NOT_FOUND',
                        'message' => 'Учебный раздел не найден.',
                    ],
                ], 404));
            Route::get('/subjects/{subject:slug}/courses/{course:slug}', [LearningController::class, 'showCourse'])
                ->scopeBindings()
                ->missing(fn () => response()->json([
                    'error' => [
                        'code' => 'LEARNING_COURSE_NOT_FOUND',
                        'message' => 'Учебный курс не найден.',
                    ],
                ], 404));
            Route::get('/subjects/{subject:slug}/courses/{course:slug}/lessons/{lesson:slug}', [LearningController::class, 'showLesson'])
                ->scopeBindings()
                ->missing(fn () => response()->json([
                    'error' => [
                        'code' => 'LEARNING_LESSON_NOT_FOUND',
                        'message' => 'Урок не найден.',
                    ],
                ], 404));
            Route::get('/subjects/{subject:slug}/courses/{course:slug}/lessons/{lesson:slug}/materials', [LearningController::class, 'showLessonMaterials'])
                ->scopeBindings()
                ->missing(fn () => response()->json([
                    'error' => [
                        'code' => 'LEARNING_LESSON_NOT_FOUND',
                        'message' => 'Урок не найден.',
                    ],
                ], 404));
            Route::get('/subjects/{subject:slug}/courses/{course:slug}/lessons/{lesson:slug}/assessment', [LearningAssessmentController::class, 'show'])
                ->scopeBindings()
                ->missing(fn () => response()->json([
                    'error' => [
                        'code' => 'LEARNING_LESSON_NOT_FOUND',
                        'message' => 'Урок не найден.',
                    ],
                ], 404)->header('Cache-Control', 'private, no-store'));
            Route::post('/subjects/{subject:slug}/courses/{course:slug}/lessons/{lesson:slug}/assessment/attempts', [LearningAssessmentController::class, 'store'])
                ->middleware('throttle:30,1')
                ->scopeBindings()
                ->missing(fn () => response()->json([
                    'error' => [
                        'code' => 'LEARNING_LESSON_NOT_FOUND',
                        'message' => 'Урок не найден.',
                    ],
                ], 404)->header('Cache-Control', 'private, no-store'));
            Route::patch('/subjects/{subject:slug}/courses/{course:slug}/lessons/{lesson:slug}/assessment/attempts/{attempt}', [LearningAssessmentController::class, 'update'])
                ->middleware('throttle:120,1')
                ->scopeBindings()
                ->missing(fn () => response()->json([
                    'error' => [
                        'code' => 'LEARNING_LESSON_NOT_FOUND',
                        'message' => 'Урок не найден.',
                    ],
                ], 404)->header('Cache-Control', 'private, no-store'));
            Route::get('/subjects/{subject:slug}/courses/{course:slug}/lessons/{lesson:slug}/assessment/attempts/{attempt}/suggestions', [LearningAssessmentController::class, 'suggest'])
                ->middleware('throttle:120,1')
                ->scopeBindings()
                ->missing(fn () => response()->json([
                    'error' => [
                        'code' => 'LEARNING_LESSON_NOT_FOUND',
                        'message' => 'Урок не найден.',
                    ],
                ], 404)->header('Cache-Control', 'private, no-store'));
            Route::post('/subjects/{subject:slug}/courses/{course:slug}/lessons/{lesson:slug}/assessment/attempts/{attempt}/submissions', [LearningAssessmentController::class, 'submit'])
                ->middleware('throttle:10,1')
                ->scopeBindings()
                ->missing(fn () => response()->json([
                    'error' => [
                        'code' => 'LEARNING_LESSON_NOT_FOUND',
                        'message' => 'Урок не найден.',
                    ],
                ], 404)->header('Cache-Control', 'private, no-store'));
            if (app()->environment(['local', 'testing'])) {
                Route::get('/subjects/{subject:slug}/courses/{course:slug}/lessons/{lesson:slug}/assessment/development-calibration-key', [LearningAssessmentController::class, 'showDevelopmentCalibrationKey'])
                    ->middleware('throttle:60,1')
                    ->scopeBindings()
                    ->missing(fn () => response()->json([
                        'error' => [
                            'code' => 'LEARNING_LESSON_NOT_FOUND',
                            'message' => 'Урок не найден.',
                        ],
                    ], 404)->header('Cache-Control', 'private, no-store'));
                Route::delete('/subjects/{subject:slug}/courses/{course:slug}/lessons/{lesson:slug}/assessment/development-state', [LearningAssessmentController::class, 'resetDevelopmentState'])
                    ->middleware('throttle:10,1')
                    ->scopeBindings()
                    ->missing(fn () => response()->json([
                        'error' => [
                            'code' => 'LEARNING_LESSON_NOT_FOUND',
                            'message' => 'Урок не найден.',
                        ],
                    ], 404)->header('Cache-Control', 'private, no-store'));
            }
            Route::get('/subjects/{subject:slug}/courses/{course:slug}/lessons/{lesson:slug}/assessment/assets/{asset}', LearningAssessmentAssetController::class)
                ->middleware('throttle:120,1')
                ->scopeBindings()
                ->missing(fn () => response()->json([
                    'error' => [
                        'code' => 'LEARNING_LESSON_NOT_FOUND',
                        'message' => 'Урок не найден.',
                    ],
                ], 404)->header('Cache-Control', 'private, no-store'));
            Route::get('/subjects/{subject:slug}/courses/{course:slug}/lessons/{lesson:slug}/video-materials', [LearningVideoController::class, 'index'])
                ->scopeBindings()
                ->missing(fn () => response()->json([
                    'error' => [
                        'code' => 'LEARNING_LESSON_NOT_FOUND',
                        'message' => 'Урок не найден.',
                    ],
                ], 404));
            Route::post('/subjects/{subject:slug}/courses/{course:slug}/lessons/{lesson:slug}/video-playback', [LearningVideoController::class, 'playback'])
                ->middleware('throttle:30,1')
                ->scopeBindings()
                ->missing(fn () => response()->json([
                    'error' => [
                        'code' => 'LEARNING_LESSON_NOT_FOUND',
                        'message' => 'Урок не найден.',
                    ],
                ], 404));
            Route::post('/subjects/{subject:slug}/courses/{course:slug}/lessons/{lesson:slug}/video-position', [LearningVideoController::class, 'savePosition'])
                ->middleware('throttle:60,1')
                ->scopeBindings()
                ->missing(fn () => response()->json([
                    'error' => [
                        'code' => 'LEARNING_LESSON_NOT_FOUND',
                        'message' => 'Урок не найден.',
                    ],
                ], 404));
            Route::get('/subjects/{subject:slug}/courses/{course:slug}/lessons/{lesson:slug}/file-materials', [LearningFileController::class, 'index'])
                ->scopeBindings()
                ->missing(fn () => response()->json([
                    'error' => [
                        'code' => 'LEARNING_LESSON_NOT_FOUND',
                        'message' => 'Урок не найден.',
                    ],
                ], 404));
            Route::post('/subjects/{subject:slug}/courses/{course:slug}/lessons/{lesson:slug}/file-deliveries', [LearningFileController::class, 'store'])
                ->middleware('throttle:20,1')
                ->scopeBindings()
                ->missing(fn () => response()->json([
                    'error' => [
                        'code' => 'LEARNING_LESSON_NOT_FOUND',
                        'message' => 'Урок не найден.',
                    ],
                ], 404));
            Route::post('/subjects/{subject:slug}/courses/{course:slug}/lessons/{lesson:slug}/file-deliveries/prewarm', [LearningFileController::class, 'prewarm'])
                ->middleware('throttle:10,1')
                ->scopeBindings()
                ->missing(fn () => response()->json([
                    'error' => [
                        'code' => 'LEARNING_LESSON_NOT_FOUND',
                        'message' => 'Урок не найден.',
                    ],
                ], 404));
            Route::get('/subjects/{subject:slug}/courses/{course:slug}/lessons/{lesson:slug}/file-deliveries/{delivery}', [LearningFileController::class, 'show'])
                ->middleware('throttle:60,1')
                ->scopeBindings()
                ->missing(fn () => response()->json([
                    'error' => [
                        'code' => 'LEARNING_LESSON_NOT_FOUND',
                        'message' => 'Урок не найден.',
                    ],
                ], 404));
            Route::post('/subjects/{subject:slug}/courses/{course:slug}/lessons/{lesson:slug}/file-deliveries/{delivery}/access-grants', [LearningFileController::class, 'grantAccess'])
                ->middleware('throttle:30,1')
                ->scopeBindings()
                ->missing(fn () => response()->json([
                    'error' => [
                        'code' => 'LEARNING_LESSON_NOT_FOUND',
                        'message' => 'Урок не найден.',
                    ],
                ], 404));
        });

    Route::prefix('admin')->middleware(['cache.headers:private;no_store', 'permission:admin.access'])->group(function (): void {
        Route::get('/reviews', [AdminReviewController::class, 'index'])->middleware('permission:reviews.read');
        Route::get('/reports/financial', AdminFinancialReportController::class)
            ->middleware('permission:reports.read');
        Route::get('/orders', [AdminOperationsController::class, 'orders'])->middleware('permission:payments.read_order');
        Route::get('/support-tickets/{id}', [SupportTicketController::class, 'show'])->whereNumber('id')->middleware('permission:support.read');
        Route::post('/support-tickets/{id}/reply', [SupportTicketController::class, 'reply'])->whereNumber('id')->middleware('permission:support.write');
        Route::get('/support-tickets/{ticket}/attachments/{attachment}', [SupportTicketController::class, 'attachment'])->middleware('permission:support.read');
        Route::get('/support-mail-receipts/{receipt}/original', [SupportTicketController::class, 'original'])->middleware('permission:support.read');
        Route::get('/support-tickets', [AdminOperationsController::class, 'tickets'])->middleware('permission:support.read');
        Route::post('/support-tickets', [AdminOperationsController::class, 'tickets'])->middleware('permission:support.write');
        Route::patch('/support-tickets/{id}', [AdminOperationsController::class, 'tickets'])->whereNumber('id')->middleware('permission:support.write');
        Route::delete('/support-tickets/{id}', [AdminOperationsController::class, 'tickets'])->whereNumber('id')->middleware('permission:support.write');
        Route::get('/tasks', [AdminOperationsController::class, 'tasks'])->middleware('permission:tasks.read');
        Route::post('/tasks', [AdminOperationsController::class, 'tasks'])->middleware('permission:tasks.write');
        Route::post('/tasks/{id}/complete', [AdminOperationsController::class, 'completeTask'])->whereNumber('id')->middleware('permission:tasks.complete_assigned');
        Route::patch('/tasks/{id}', [AdminOperationsController::class, 'tasks'])->whereNumber('id')->middleware('permission:tasks.write');
        Route::delete('/tasks/{id}', [AdminOperationsController::class, 'tasks'])->whereNumber('id')->middleware('permission:tasks.write');
        Route::get('/short-links', [AdminOperationsController::class, 'links'])->middleware('permission:short-links.read');
        Route::post('/short-links', [AdminOperationsController::class, 'links'])->middleware('permission:short-links.write');
        Route::patch('/short-links/{id}', [AdminOperationsController::class, 'links'])->whereNumber('id')->middleware('permission:short-links.write');
        Route::delete('/short-links/{id}', [AdminOperationsController::class, 'links'])->whereNumber('id')->middleware('permission:short-links.write');
        Route::get('/promos', [AdminOperationsController::class, 'promos'])->middleware('permission:promos.read');
        Route::post('/promos', [AdminOperationsController::class, 'promos'])->middleware('permission:promos.create');
        Route::delete('/promos/{id}', [AdminOperationsController::class, 'deleteOwnPromo'])->whereNumber('id')->middleware('permission:promos.delete_own');
        Route::patch('/promos/{id}', [AdminOperationsController::class, 'promos'])->whereNumber('id')->middleware('permission:promos.write');
        Route::get('/reviews/options', [AdminReviewController::class, 'options'])->middleware('permission:reviews.read');
        Route::get('/reviews/{review}', [AdminReviewController::class, 'show'])->middleware('permission:reviews.read');
        Route::post('/reviews', [AdminReviewController::class, 'store'])->middleware(['permission:reviews.write', 'throttle:30,1']);
        Route::patch('/reviews/{review}', [AdminReviewController::class, 'update'])->middleware(['permission:reviews.write', 'throttle:60,1']);
        Route::delete('/reviews/{review}', [AdminReviewController::class, 'destroy'])->middleware(['permission:reviews.write', 'throttle:30,1']);
        Route::patch('/reviews/{review}/publication', [AdminReviewController::class, 'publication'])
            ->middleware(['permission:reviews.publish', 'throttle:30,1'])->name('admin.reviews.publication');
        Route::get('/me', [AdminUserController::class, 'me'])
            ->middleware('permission:admin.self.view');
        Route::patch('/me', [AdminUserController::class, 'updateMe'])
            ->middleware(['permission:admin.self.update', 'throttle:30,1']);
        Route::get('/users', [AdminUserManagementController::class, 'index'])
            ->middleware('permission:users.read');
        Route::get('/user-managers', [AdminUserManagementController::class, 'managers'])
            ->middleware(['permission:users.read', 'permission:users.assign_manager']);
        Route::get('/legacy-users', [AdminLegacyUserController::class, 'index'])
            ->middleware('permission:users.read');
        Route::get('/legacy-users/unresolved', [AdminLegacyUserController::class, 'unresolved'])
            ->middleware('permission:users.read');
        Route::prefix('legacy-users/{sourceMemberId}')
            ->whereNumber('sourceMemberId')
            ->middleware('permission:users.read')
            ->group(function (): void {
                Route::get('/', [AdminLegacyUserController::class, 'show']);
                Route::get('/orders', [AdminLegacyUserController::class, 'orders'])
                    ->middleware('permission:payments.read_order');
                Route::get('/accesses', [AdminLegacyUserController::class, 'accesses'])
                    ->middleware('permission:access.read');
                Route::get('/records', [AdminLegacyUserController::class, 'records']);
            });
        Route::post('/users/{user}/impersonation', [AdminImpersonationController::class, 'start'])
            ->middleware(['permission:users.read', 'throttle:20,1']);
        Route::prefix('users/{user}')->middleware('permission:users.read')->group(function (): void {
            Route::get('/', [AdminUserManagementController::class, 'show']);
            Route::get('/orders', [AdminUserManagementController::class, 'orders'])->middleware('permission:payments.read_order');
            Route::get('/entitlements', [AdminUserManagementController::class, 'entitlements'])->middleware('permission:access.read');
            Route::get('/courses', [AdminUserManagementController::class, 'courses'])->middleware('permission:access.read');
            Route::get('/events', [AdminUserManagementController::class, 'events'])->middleware('permission:audit.read');
            Route::patch('/profile', [AdminUserManagementController::class, 'updateProfile'])->middleware(['permission:users.update', 'throttle:30,1']);
            Route::patch('/manager', [AdminUserManagementController::class, 'updateManager'])->middleware(['permission:users.assign_manager', 'throttle:30,1']);
            Route::put('/tags', [AdminUserManagementController::class, 'updateTags'])->middleware(['permission:users.update', 'throttle:30,1']);
            Route::post('/comments', [AdminUserManagementController::class, 'comment'])->middleware(['permission:users.update', 'throttle:30,1']);
            Route::delete('/test-account', [AdminUserManagementController::class, 'deleteTestAccount'])
                ->middleware(['permission:users.delete', 'throttle:10,1']);
        });
        Route::get('/assessment-reviews', [AdminAssessmentReviewController::class, 'index'])
            ->middleware('permission:assessment-reviews.read');
        Route::get('/assessment-reviews/{attempt}', [AdminAssessmentReviewController::class, 'show'])
            ->middleware('permission:assessment-reviews.read');
        Route::patch('/assessment-reviews/{attempt}/items/{review}', [AdminAssessmentReviewController::class, 'update'])
            ->middleware(['permission:assessment-reviews.review', 'throttle:assessment-review-update']);
        Route::post('/assessment-reviews/{attempt}/finalizations', [AdminAssessmentReviewController::class, 'finalize'])
            ->middleware(['permission:assessment-reviews.review', 'throttle:assessment-review-finalize']);
        Route::post('/video-watermarks/lookup', VideoWatermarkAdminController::class)
            ->middleware(['permission:video-watermarks.read', 'throttle:30,1']);
        Route::post('/trusted-browsers/release-challenges', [TrustedBrowserAdminController::class, 'startRelease'])
            ->middleware([
                'permission:trusted-browser.release',
                EnsureTrustedBrowserRequestOrigin::class,
                'throttle:30,1',
            ]);
        Route::post('/trusted-browsers/release-challenges/{challenge}/approve', [TrustedBrowserAdminController::class, 'approveRelease'])
            ->middleware([
                'permission:trusted-browser.release',
                EnsureTrustedBrowserRequestOrigin::class,
                'throttle:30,1',
            ]);
        Route::post('/trusted-browsers/release-challenges/{challenge}/verify', [TrustedBrowserAdminController::class, 'verifyRelease'])
            ->middleware([
                'permission:trusted-browser.release',
                EnsureTrustedBrowserRequestOrigin::class,
                'throttle:30,1',
            ]);
    });

    Route::prefix('payments')->group(function (): void {
        Route::get('/coins', [PaymentCoinsController::class, 'balance'])
            ->middleware('permission:payments.read_order');
        Route::post('/orders/{order}/coins/quote', [PaymentCoinsController::class, 'quote'])
            ->middleware(['permission:payments.create_order', 'throttle:payment-create-order']);
        Route::post('/orders/{order}/coins', [PaymentCoinsController::class, 'apply'])
            ->middleware(['permission:payments.create_order', 'throttle:payment-create-order']);
        Route::post('/orders/{order}/coin-settlement', [PaymentCoinsController::class, 'settle'])
            ->middleware(['permission:payments.create_order', 'throttle:payment-create-attempt']);
        Route::post('/orders/{order}/discount-settlement', [PaymentCoinsController::class, 'settle'])
            ->middleware(['permission:payments.create_order', 'throttle:payment-create-attempt']);
        Route::post('/orders/{order}/promo', [PaymentPromosController::class, 'apply'])
            ->middleware(['permission:payments.create_order', 'throttle:payment-create-order']);
        Route::get('/history', PaymentHistoryController::class)->middleware('permission:payments.read_order');
        Route::get('/methods', [PaymentsController::class, 'methods'])
            ->middleware('permission:payments.create_attempt');
        Route::post('/orders', [PaymentsController::class, 'createOrder'])
            ->middleware(['permission:payments.create_order', 'throttle:payment-create-order']);
        Route::post('/orders/{order}/attempts', [PaymentsController::class, 'createAttempt'])
            ->middleware(['permission:payments.create_attempt', 'throttle:payment-create-attempt']);
        Route::get('/orders/{order}', [PaymentsController::class, 'showOrder'])
            ->middleware('permission:payments.read_order');
        Route::post('/orders/{order}/retry', [PaymentsController::class, 'retry'])
            ->middleware(['permission:payments.retry_attempt', 'throttle:payment-operator-mutation']);
        Route::post('/orders/{order}/cancel', [PaymentsController::class, 'cancel'])
            ->middleware(['permission:payments.manual_cancel', 'throttle:payment-operator-mutation']);
        Route::post('/orders/{order}/refund', [PaymentsController::class, 'refund'])
            ->middleware(['permission:payments.manual_refund', 'throttle:payment-operator-mutation']);
        Route::post('/events/{event}/replay', [PaymentsController::class, 'replayEvent'])
            ->middleware(['permission:payments.replay_event', 'throttle:payment-operator-mutation']);
    });
});
