<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TrustedBrowser\TrustedBrowserException;
use App\Services\TrustedBrowser\TrustedBrowserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TrustedBrowserController extends Controller
{
    public function __construct(private readonly TrustedBrowserService $trustedBrowsers) {}

    public function status(Request $request): JsonResponse
    {
        return response()->json($this->trustedBrowsers->status($request, $this->user($request)));
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->trustedBrowsers->listSlots($request, $this->user($request)));
    }

    public function revoke(Request $request, int $slot): JsonResponse
    {
        try {
            return response()->json($this->trustedBrowsers->revoke($request, $this->user($request), $slot));
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
