<?php

namespace App\Filament\Auth;

use App\Models\VideoWatermarkSession;
use App\Services\SessionTermination;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse;

final class LogoutController
{
    public function __invoke(): LogoutResponse
    {
        app(SessionTermination::class)->terminate(request(), VideoWatermarkSession::END_REASON_LOGOUT);

        return app(LogoutResponse::class);
    }
}
