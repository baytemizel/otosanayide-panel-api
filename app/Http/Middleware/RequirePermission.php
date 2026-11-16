<?php

namespace App\Http\Middleware;

use App\Exceptions\HttpError;
use App\Panel\Audit\Audit;
use App\Panel\Auth\Member;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Rota yetkisi: `perm:a.b` ya da herhangi biri yeterli `perm:a.b|c.d`. Ret denetime yazilir. */
final class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $member = Member::from($request);
        foreach (explode('|', $permissions) as $permission) {
            if ($member->can($permission)) {
                return $next($request);
            }
        }
        Audit::record($request, 'access.denied', 'route', $request->method().' '.$request->path(), null, ['required' => $permissions], 'denied');

        throw new HttpError(403, 'Bu işlem için panel yetkiniz yok.');
    }
}
