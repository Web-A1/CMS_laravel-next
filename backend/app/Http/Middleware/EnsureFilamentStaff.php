<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Rbac;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureFilamentStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('web');
        $actor = $guard->user();
        if (! $actor instanceof User) {
            $path = $request->is('admin', 'admin/*') ? '/'.$request->path() : '/admin';
            // The return value is constructed from a local path, never a next input.
            if (str_contains($path, '%') || str_contains($path, '\\') || preg_match('/[\x00-\x20]/', $path)) {
                $path = '/admin';
            }

            return redirect('/auth?'.http_build_query(['mode' => 'login', 'next' => $path], '', '&', PHP_QUERY_RFC3986));
        }
        $current = User::query()->find($actor->getKey());
        abort_unless($current instanceof User && ! $current->blocksAuthenticationForMigration(), 403);
        abort_unless($request->is('admin/logout') || Rbac::hasPermission($current, 'admin.access'), 403);
        Auth::shouldUse('web');
        $guard->setUser($current);

        return $next($request);
    }
}
