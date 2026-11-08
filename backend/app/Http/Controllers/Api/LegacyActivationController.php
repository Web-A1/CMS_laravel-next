<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\LegacyUsers\LegacyUserActivationService;
use App\Services\TrustedBrowser\TrustedBrowserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\IpUtils;

final class LegacyActivationController extends Controller
{
    public function __construct(private readonly LegacyUserActivationService $activation, private readonly TrustedBrowserService $trustedBrowsers) {}

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);

        return $this->respond($this->activation->start($data['email']), 202);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        [$id, $capability] = $this->credential($request);
        $data = $request->validate(['code' => ['required', 'string', 'size:6']]);

        return $this->respond($this->activation->verifyEmail($id, $capability, $data['code']));
    }

    public function password(Request $request): JsonResponse
    {
        [$id, $capability] = $this->credential($request);
        $data = $request->validate(['password' => ['required', 'string', 'min:8', 'max:255']]);

        return $this->respond($this->activation->password($id, $capability, $data['password']));
    }

    public function startPhone(Request $request): JsonResponse
    {
        [$id, $capability] = $this->credential($request);
        $data = $request->validate(['phone' => ['required', 'string', 'max:30']]);

        return $this->respond($this->activation->startPhone($id, $capability, $data['phone']), 202);
    }

    public function status(Request $request): JsonResponse
    {
        [$id, $capability] = $this->credential($request);

        return $this->respond($this->activation->status($id, $capability));
    }

    public function complete(Request $request): JsonResponse
    {
        [$id, $capability] = $this->credential($request);
        $result = $this->activation->complete($id, $capability);
        if (isset($result['error'])) {
            return $this->respond($result);
        }
        $user = User::query()->findOrFail($result['user_id']);
        Auth::guard('web')->login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        $this->trustedBrowsers->rebindCurrentSession($request, $user);

        return $this->respond(['status' => 'completed', 'step' => 'done', 'authenticated' => true, 'next_step' => 'lk']);
    }

    public function callback(Request $request): JsonResponse
    {
        $allowed = config('services.redsms.webhook_allowed_ips', []);
        if (! in_array(config('app.env'), ['local', 'testing'], true)
            && (! is_array($allowed) || $allowed === [] || ! IpUtils::checkIp((string) $request->ip(), $allowed))) {
            return $this->respond(['error' => ['code' => 'AUTH_FORBIDDEN', 'message' => 'Forbidden']], 403);
        }
        $data = $request->validate([
            'verification_id' => ['nullable', 'uuid'], 'ext_id' => ['nullable', 'uuid'],
            'provider_uuid' => ['nullable', 'string', 'max:120'], 'uuid' => ['nullable', 'string', 'max:120'],
            'provider_event_id' => ['nullable', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:30'],
            'replacedFrom' => ['nullable', 'string', 'regex:/^\+?[\d ()-]{5,30}$/D'],
            'status' => ['required', 'string', 'max:64'],
        ]);
        $this->activation->callback($data);

        return $this->respond(['status' => 'accepted']);
    }

    private function credential(Request $request): array
    {
        $data = $request->validate(['activation_id' => ['required', 'uuid'], 'capability' => ['required', 'string', 'size:64']]);

        return [$data['activation_id'], $data['capability']];
    }

    private function respond(array $result, int $status = 200): JsonResponse
    {
        $status = $result['http_status'] ?? $status;
        unset($result['http_status']);

        return response()->json($result, $status)->header('Cache-Control', 'private, no-store');
    }
}
