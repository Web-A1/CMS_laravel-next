<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TrustedBrowser\TrustedBrowserException;
use App\Services\TrustedBrowser\TrustedBrowserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TrustedBrowserChallengeController extends Controller
{
    public function __construct(private readonly TrustedBrowserService $trustedBrowsers) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'purpose' => ['required', 'string', Rule::in(['enroll', 'revoke'])],
            'slot_number' => [
                Rule::requiredIf(fn () => $request->input('purpose') === 'revoke'),
                'nullable',
                'integer',
                'between:1,3',
            ],
            'password' => ['nullable', 'string', 'max:255'],
            'browser_label' => ['nullable', 'string', 'max:80'],
            'os_label' => ['nullable', 'string', 'max:80'],
            'device_label' => ['nullable', 'string', 'max:80'],
        ]);

        try {
            $payload = $this->trustedBrowsers->startUserChallenge($request, $this->user($request), $data);

            return response()->json($payload, 202);
        } catch (TrustedBrowserException $exception) {
            return $this->errorResponse($exception);
        }
    }

    public function verify(Request $request, string $challenge): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);

        try {
            return response()->json($this->trustedBrowsers->verifyUserChallenge(
                $request,
                $this->user($request),
                $challenge,
                $data['code'],
            ));
        } catch (TrustedBrowserException $exception) {
            return $this->errorResponse($exception);
        }
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
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
