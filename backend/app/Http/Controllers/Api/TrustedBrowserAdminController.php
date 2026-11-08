<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TrustedBrowser\TrustedBrowserException;
use App\Services\TrustedBrowser\TrustedBrowserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TrustedBrowserAdminController extends Controller
{
    public function __construct(private readonly TrustedBrowserService $trustedBrowsers) {}

    public function startRelease(Request $request): JsonResponse
    {
        $data = $request->validate([
            'target_user_id' => ['required', 'integer', 'exists:users,id'],
            'slot' => ['required', 'integer', 'between:1,3'],
            'case_id' => ['required', 'string', 'max:120'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $payload = $this->trustedBrowsers->startSupportRelease(
                $request,
                $this->operator($request),
                User::query()->findOrFail($data['target_user_id']),
                $data['slot'],
                $data['case_id'],
                $data['reason'],
                $this->idempotencyKey($request),
            );

            return response()->json($payload, ($payload['status'] ?? null) === 'released' ? 200 : 202);
        } catch (TrustedBrowserException $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function approveRelease(Request $request, string $challenge): JsonResponse
    {
        try {
            return response()->json($this->trustedBrowsers->approveSupportRelease(
                $request,
                $this->operator($request),
                $challenge,
            ));
        } catch (TrustedBrowserException $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function verifyRelease(Request $request, string $challenge): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        try {
            return response()->json($this->trustedBrowsers->verifySupportRelease(
                $request,
                $this->operator($request),
                $challenge,
                $data['code'],
                $this->idempotencyKey($request),
            ));
        } catch (TrustedBrowserException $exception) {
            return $this->errorResponse($exception);
        }
    }

    private function operator(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function idempotencyKey(Request $request): string
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));
        if ($key === '' || mb_strlen($key) > 200) {
            throw new TrustedBrowserException(
                'TRUSTED_BROWSER_IDEMPOTENCY_KEY_REQUIRED',
                'Передайте корректный Idempotency-Key.',
                422,
            );
        }

        return $key;
    }

    private function errorResponse(TrustedBrowserException $exception): JsonResponse
    {
        return response()->json([
            'error' => array_merge([
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
                'request_id' => (string) Str::uuid(),
            ], $exception->meta),
        ], $exception->httpStatus);
    }
}
