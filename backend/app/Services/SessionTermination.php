<?php

namespace App\Services;

use App\Services\VideoWatermarks\VideoWatermarkSessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SessionTermination
{
    public function __construct(
        private readonly AdminImpersonationService $impersonation,
        private readonly VideoWatermarkSessionService $videoWatermarks,
    ) {}

    public function terminate(Request $request, string $reason): void
    {
        try {
            $this->impersonation->stop($request);
        } catch (Throwable $exception) {
            Log::warning('Admin impersonation context close failed during logout.', [
                'exception' => $exception::class,
            ]);
        }

        try {
            $this->videoWatermarks->closeCurrent($request, $reason);
        } catch (Throwable $exception) {
            Log::warning('Video watermark session close failed during logout.', [
                'exception' => $exception::class,
            ]);
        } finally {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }
    }
}
