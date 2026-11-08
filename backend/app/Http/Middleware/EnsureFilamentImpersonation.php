<?php

namespace App\Http\Middleware;

use App\Services\AdminImpersonationService;
use App\Support\ImpersonationContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureFilamentImpersonation
{
    public function __construct(private readonly AdminImpersonationService $impersonation) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Persistent Livewire middleware replays the original GET method.
        // Resolve and enforce against the actual update request, not that replay.
        $actual = request();
        if ($actual->is('admin/logout')) {
            return $next($request);
        }
        $resolution = $this->impersonation->resolve($actual);
        abort_if($resolution['status'] === 'expired', 410, 'Контекст просмотра устарел. Вернитесь в админку.');
        if (ImpersonationContext::isActive($actual)) {
            if ($actual->isMethod('GET')) {
                return redirect('/lk');
            }
            abort(403, 'В режиме просмотра пользователя доступны только чтение и возврат в админку.');
        }

        return $next($request);
    }
}
