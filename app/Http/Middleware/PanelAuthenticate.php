<?php

namespace App\Http\Middleware;

use App\Exceptions\HttpError;
use App\Panel\Auth\Member;
use App\Panel\Auth\PanelAuth;
use App\Panel\Security\Ip;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/** Bearer erisim jetonu zorunlu; genel IP listesi, uye IP listesi ve uye basina hiz siniri. */
final class PanelAuthenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Ip::globallyAllowed($request->ip())) {
            throw new HttpError(403, 'Bu adresten panel erişimi kapalı.');
        }
        if (preg_match('/^Bearer (opa_[A-Za-z0-9_-]{20,80})$/D', (string) $request->headers->get('Authorization'), $m) !== 1) {
            throw new HttpError(401, 'Giriş gerekli.');
        }
        $member = PanelAuth::resolve($m[1], $request->ip());
        $limit = (int) config('panel.rate_limits.member_per_minute');
        if (RateLimiter::hit('panel-member:'.$member->accountId, 60) > $limit) {
            throw new HttpError(429, 'Çok fazla işlem. Bir dakika sonra yeniden deneyin.');
        }
        $request->attributes->set(Member::ATTRIBUTE, $member);

        return $next($request);
    }
}
