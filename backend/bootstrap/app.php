<?php

use App\Http\Middleware\AddSecurityHeaders;
use App\Http\Middleware\AdminPanelResponse;
use App\Http\Middleware\EnsureImpersonationReadOnly;
use App\Http\Middleware\EnsureMigrationAccountActive;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsurePhoneVerified;
use App\Http\Middleware\ResolveImpersonationContext;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        __DIR__.'/../app/Console/Commands',
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(App\Http\Middleware\DemoReadOnly::class);
        $middleware->append(AddSecurityHeaders::class);
        $middleware->append(AdminPanelResponse::class);

        $middleware->redirectGuestsTo(static fn (Request $request) => $request->is('api/*') ? null : '/auth');

        $middleware->statefulApi();
        // Public guest intake has no session-dependent action; stateful browser requests still need to reach it.
        $middleware->preventRequestForgery(except: ['api/v1/support-tickets']);

        $middleware->api(append: [
            EnsureMigrationAccountActive::class,
            ResolveImpersonationContext::class,
            EnsureImpersonationReadOnly::class,
        ]);
        $middleware->appendToPriorityList(AuthenticatesRequests::class, EnsureMigrationAccountActive::class);
        $middleware->appendToPriorityList(EnsureMigrationAccountActive::class, ResolveImpersonationContext::class);
        $middleware->appendToPriorityList(ResolveImpersonationContext::class, EnsureImpersonationReadOnly::class);

        $middleware->alias([
            'migration.active' => EnsureMigrationAccountActive::class,
            'permission' => EnsurePermission::class,
            'phone.verified' => EnsurePhoneVerified::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            if ($request->is('api/v1/support-tickets', 'api/v1/admin/support-tickets*', 'api/v1/admin/support-mail-receipts*')) {
                $status = $response->getStatusCode();
                if ($status >= 400) {
                    $payload = json_decode($response->getContent(), true);
                    $code = match ($status) {
                        401 => 'SUPPORT_UNAUTHENTICATED', 403 => 'SUPPORT_FORBIDDEN', 404 => 'SUPPORT_NOT_FOUND',
                        405 => 'SUPPORT_METHOD_NOT_ALLOWED', 409 => 'SUPPORT_CONFLICT', 413 => 'SUPPORT_TOO_LARGE',
                        422 => 'SUPPORT_VALIDATION_FAILED', 429 => 'SUPPORT_RATE_LIMIT', default => 'SUPPORT_UNAVAILABLE',
                    };
                    $message = match ($status) {
                        409 => 'История или содержимое изменились. Обновите данные и повторите действие.',
                        413 => 'Общий размер файлов слишком большой. Уменьшите его и повторите отправку.',
                        422 => 'Проверьте поля формы.', 429 => 'Слишком много обращений. Повторите позже.',
                        default => $status >= 500 ? 'Поддержка временно недоступна. Повторите отправку позже.' : 'Действие недоступно.',
                    };
                    $data = ['error' => ['code' => $code, 'message' => $message]];
                    if (isset($payload['errors'])) {
                        $data['error']['fields'] = $payload['errors'];
                    }
                    if (isset($payload['retry_after'])) {
                        $data['retry_after'] = $payload['retry_after'];
                    }
                    $headers = $response->headers->has('Retry-After') ? ['Retry-After' => $response->headers->get('Retry-After')] : [];
                    $response = response()->json($data, $status, $headers);
                }
                $response->headers->set('Cache-Control', 'private, no-store');

                return $response;
            }
            if (AdminPanelResponse::applies($request)) {
                return AdminPanelResponse::headers($response);
            }

            if (! $request->is('api/v1/admin/reviews', 'api/v1/admin/reviews/*')) {
                return $response;
            }
            $status = $response->getStatusCode();
            if ($status >= 400) {
                $payload = json_decode($response->getContent(), true);
                if (! is_array($payload) || ! isset($payload['error']['code'], $payload['error']['message'])) {
                    [$code, $message] = match ($status) {
                        401 => ['ADMIN_REVIEW_UNAUTHENTICATED', 'Войдите в учётную запись.'],
                        403 => ['ADMIN_REVIEW_FORBIDDEN', 'Нет доступа к управлению отзывами.'],
                        404 => ['ADMIN_REVIEW_NOT_FOUND', 'Отзыв не найден.'],
                        422 => ['ADMIN_REVIEW_VALIDATION_FAILED', 'Проверьте поля отзыва.'],
                        429 => ['ADMIN_REVIEW_RATE_LIMIT', 'Слишком много запросов. Попробуйте позже.'],
                        default => ['ADMIN_REVIEW_UNAVAILABLE', 'Сервис отзывов временно недоступен.'],
                    };
                    $headers = $response->headers->has('Retry-After') ? ['Retry-After' => $response->headers->get('Retry-After')] : [];
                    $response = response()->json(['error' => ['code' => $code, 'message' => $message]], $status >= 500 ? 503 : $status, $headers);
                }
            }
            $response->headers->set('Cache-Control', 'private, no-store');
            if ($exception instanceof ThrottleRequestsException) {
                $response->headers->add($exception->getHeaders());
            }

            return $response;
        });
        $exceptions->shouldRenderJsonWhen(static fn (Request $request, Throwable $e): bool => $request->is('api/*') || $request->expectsJson());

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $retryAfterHeader = $e->getHeaders()['Retry-After'] ?? null;
            $retryAfter = is_numeric($retryAfterHeader) ? (int) $retryAfterHeader : null;

            $payload = [
                'error' => [
                    'code' => 'VERIFY_RATE_LIMIT',
                    'message' => $retryAfter && $retryAfter > 0
                        ? "Слишком много попыток. Повторите через {$retryAfter} сек."
                        : 'Слишком много попыток. Попробуйте позже.',
                    'request_id' => (string) $request->header('X-Request-Id', ''),
                ],
            ];

            if ($retryAfter && $retryAfter > 0) {
                $payload['retry_after'] = $retryAfter;
            }

            return response()->json($payload, Response::HTTP_TOO_MANY_REQUESTS);
        });
    })->create();
