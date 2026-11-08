<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureMigrationAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $current = $user instanceof User ? User::query()->find($user->id) : null;

        if ($user instanceof User && $current instanceof User && $current->blocksAuthenticationForMigration()) {
            $this->forgetAuthenticatedState($request, $user);

            return response()->json([
                'error' => [
                    'code' => 'AUTH_ACCOUNT_MIGRATION_HELD',
                    'message' => 'Аккаунт импортирован для проверки данных. Вход будет включён отдельно.',
                ],
            ], 403)->header('Cache-Control', 'private, no-store');
        }

        return $next($request);
    }

    private function forgetAuthenticatedState(Request $request, User $user): void
    {
        $accessToken = $user->currentAccessToken();
        if ($accessToken && method_exists($accessToken, 'delete')) {
            $accessToken->delete();
        }
        $user->tokens()->delete();

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
