<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ImpersonationContext
{
    public const SESSION_KEY = 'admin_impersonation';

    public const EXPIRED_SESSION_KEY = 'admin_impersonation_expired';

    public static function effectiveUser(Request $request): User
    {
        $subject = $request->attributes->get('impersonation.subject');
        if ($subject instanceof User) {
            return $subject;
        }

        $actor = $request->user();
        if ($actor instanceof User) {
            return $actor;
        }

        abort(Response::HTTP_UNAUTHORIZED);
    }

    public static function isActive(Request $request): bool
    {
        return $request->attributes->get('impersonation.active') === true;
    }

    public static function context(Request $request): ?array
    {
        $context = $request->attributes->get('impersonation.context');

        return is_array($context) ? $context : null;
    }

    public static function mediaDisabledResponse(): Response
    {
        return response()->json([
            'error' => [
                'code' => 'IMPERSONATION_MEDIA_DISABLED',
                'message' => 'Видео и PDF недоступны в режиме просмотра пользователя.',
            ],
        ], Response::HTTP_FORBIDDEN)->header('Cache-Control', 'private, no-store');
    }
}
