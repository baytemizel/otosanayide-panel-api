<?php

namespace App\Http\Controllers;

use App\Exceptions\HttpError;
use App\Panel\Audit\Audit;
use App\Panel\Auth\LoginFailed;
use App\Panel\Auth\MfaFailed;
use App\Panel\Auth\PanelAuth;
use App\Panel\Auth\Permissions;
use App\Panel\Auth\RefreshReused;
use App\Panel\Auth\Sessions;
use App\Panel\Http\Respond;
use App\Panel\Security\Ip;
use App\Panel\Support\PanelDb;
use App\Panel\Support\Secret;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * /api/v1/auth. Tarayici (Origin var): yenileme jetonu yalniz HttpOnly cerezde, govdede donmez.
 * Origin yok (Postman, CLI): yenileme jetonu govdede doner ve govdeyle gonderilir.
 */
final class AuthController extends PanelController
{
    public function login(Request $request): JsonResponse
    {
        $this->assertIp($request);
        $in = $this->input($request, ['email' => ['required', 'string', 'max:254'], 'password' => ['required', 'string', 'max:1024']]);
        $email = Secret::normalizeEmail($in['email']);
        $limit = (int) config('panel.rate_limits.login_per_minute');
        if (RateLimiter::hit('panel-login-ip:'.Ip::hash($request->ip()), 60) > $limit * 4 || RateLimiter::hit('panel-login-email:'.Secret::emailKey($email), 60) > $limit) {
            throw new HttpError(429, 'Çok fazla giriş denemesi. Bir dakika sonra yeniden deneyin.');
        }
        try {
            $result = PanelAuth::login($email, $in['password'], $request->ip());
        } catch (LoginFailed $e) {
            Audit::record($request, 'auth.login_failed', 'account', $e->accountId, null, ['emailKey' => Secret::emailKey($email)], 'failed', $e->accountId);
            throw new HttpError(401, PanelAuth::FAILED);
        }
        $accountId = $result['accountId'];
        unset($result['accountId']);
        Audit::record($request, $result['step'] === 'mfa' ? 'auth.password_ok' : 'auth.mfa_enrollment_started', 'account', $accountId, null, null, 'success', $accountId);

        return Respond::data($result);
    }

    public function mfa(Request $request): JsonResponse
    {
        $this->assertIp($request);
        $in = $this->input($request, ['challengeToken' => ['required', 'string', 'regex:/^opc_[A-Za-z0-9_-]{20,80}$/'], 'code' => ['required', 'string', 'regex:/^\d{6}$/']]);
        if (RateLimiter::hit('panel-mfa-ip:'.Ip::hash($request->ip()), 60) > (int) config('panel.rate_limits.login_per_minute') * 4) {
            throw new HttpError(429, 'Çok fazla doğrulama denemesi. Bir dakika sonra yeniden deneyin.');
        }
        try {
            $result = PanelAuth::verifyMfa($in['challengeToken'], $in['code'], $request->ip(), (string) $request->userAgent());
        } catch (MfaFailed $e) {
            Audit::record($request, 'auth.mfa_failed', 'account', $e->accountId, null, null, 'failed', $e->accountId);
            throw new HttpError(401, 'Doğrulama kodu hatalı.');
        } catch (LoginFailed $e) {
            Audit::record($request, 'auth.login_failed', 'account', $e->accountId, null, ['step' => 'mfa'], 'failed', $e->accountId);
            throw new HttpError(401, PanelAuth::FAILED);
        }
        Audit::record($request, $result['enrolled'] ? 'auth.mfa_enrolled' : 'auth.login', 'account', $result['accountId'], null, ['sessionId' => $result['tokens']['sessionId']], 'success', $result['accountId']);
        if ($result['enrolled']) {
            Audit::record($request, 'auth.login', 'account', $result['accountId'], null, ['sessionId' => $result['tokens']['sessionId']], 'success', $result['accountId']);
        }

        return $this->tokens($request, $result['tokens'], $result['accountId']);
    }

    public function refresh(Request $request): JsonResponse
    {
        $this->assertIp($request);
        $token = $this->refreshToken($request);
        try {
            $result = PanelAuth::refresh($token, $request->ip(), (string) $request->userAgent());
        } catch (RefreshReused $e) {
            Audit::record($request, 'auth.refresh_reused', 'account', $e->accountId, null, null, 'denied', $e->accountId);

            return $this->clearCookie($request, Respond::error(PanelAuth::INVALID_SESSION, 401));
        } catch (HttpError $e) {
            return $this->clearCookie($request, Respond::error($e->getMessage(), $e->status));
        }

        return $this->tokens($request, $result['tokens'], $result['accountId']);
    }

    /** Bearer varsa o oturumun ailesi, yoksa yenileme jetonunun ailesi kapanir. */
    public function logout(Request $request): JsonResponse
    {
        $bearer = preg_match('/^Bearer (opa_[A-Za-z0-9_-]{20,80})$/D', (string) $request->headers->get('Authorization'), $m) === 1 ? $m[1] : null;
        $accountId = null;
        if ($bearer !== null) {
            $row = PanelDb::table('panel_access_tokens as t')->join('panel_sessions as s', 's.id', '=', 't.session_id')->where('t.token_hash', Secret::hash($bearer))->first(['s.family_id', 's.account_id']);
            if ($row !== null) {
                Sessions::revokeFamily($row->family_id, 'logout');
                $accountId = $row->account_id;
            }
        }
        if ($accountId === null) {
            // Bearer yok ya da cozulemedi (suresi dolup silinmis olabilir): yenileme jetonunun ailesi kapanir.
            $token = $this->refreshToken($request, false);
            $accountId = $token === null ? null : PanelAuth::logoutByRefresh($token);
        }
        if ($accountId !== null) {
            Audit::record($request, 'auth.logout', 'account', $accountId, null, null, 'success', $accountId);
        }

        return $this->clearCookie($request, Respond::data(['loggedOut' => true]));
    }

    public function me(Request $request): JsonResponse
    {
        $m = $this->member($request);

        return Respond::data($m->view() + ['sessionId' => $m->sessionId, 'familyId' => $m->familyId]);
    }

    public function sessions(Request $request): JsonResponse
    {
        $m = $this->member($request);

        return Respond::data(array_map(static fn (array $s): array => $s + ['current' => $s['familyId'] === $m->familyId], Sessions::active($m->accountId)));
    }

    public function revokeSession(Request $request, string $familyId): JsonResponse
    {
        $m = $this->member($request);
        if (! PanelDb::table('panel_sessions')->where('family_id', $familyId)->where('account_id', $m->accountId)->exists()) {
            $this->notFound('Oturum');
        }
        Sessions::revokeFamily($familyId, 'logout');
        $this->audit($request, 'auth.session_revoked', 'panel_session', $familyId);

        return Respond::data(['revoked' => true]);
    }

    public function permissions(Request $request): JsonResponse
    {
        $this->member($request);

        return Respond::data(['catalog' => Permissions::catalog()]);
    }

    private function assertIp(Request $request): void
    {
        if (! Ip::globallyAllowed($request->ip())) {
            throw new HttpError(403, 'Bu adresten panel erişimi kapalı.');
        }
    }

    private function refreshToken(Request $request, bool $required = true): ?string
    {
        $cookie = config('panel.refresh_cookie.name');
        $browser = $request->headers->has('Origin');
        $token = $browser ? $request->cookies->get($cookie) : $request->json('refreshToken');
        if (! is_string($token) || preg_match('/^opr_[A-Za-z0-9_-]{20,80}$/D', $token) !== 1) {
            if ($required) {
                throw new HttpError(401, PanelAuth::INVALID_SESSION);
            }

            return null;
        }

        return $token;
    }

    /** @param array<string,string> $tokens */
    private function tokens(Request $request, array $tokens, string $accountId): JsonResponse
    {
        $p = Permissions::forAccount($accountId);
        $body = [
            'accessToken' => $tokens['accessToken'],
            'accessTokenExpiresAt' => $tokens['accessTokenExpiresAt'],
            'refreshTokenExpiresAt' => $tokens['refreshTokenExpiresAt'],
            'sessionExpiresAt' => $tokens['sessionExpiresAt'],
            'roles' => $p['roles'],
        ];
        if (! $request->headers->has('Origin')) {
            $body['refreshToken'] = $tokens['refreshToken'];
        }
        $response = Respond::data($body);
        if ($request->headers->has('Origin')) {
            $response->headers->setCookie($this->cookie($tokens['refreshToken'], strtotime($tokens['sessionExpiresAt']) ?: 0));
        }

        return $response;
    }

    private function cookie(string $value, int $expires): Cookie
    {
        $c = (array) config('panel.refresh_cookie');

        return Cookie::create((string) $c['name'], $value, $expires, (string) $c['path'], $c['domain'] ?? null, (bool) $c['secure'], true, false, Cookie::SAMESITE_STRICT);
    }

    private function clearCookie(Request $request, JsonResponse $response): JsonResponse
    {
        if ($request->headers->has('Origin')) {
            $response->headers->setCookie($this->cookie('', 1));
        }

        return $response;
    }
}
