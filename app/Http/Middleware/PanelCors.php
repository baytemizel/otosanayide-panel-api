<?php

namespace App\Http\Middleware;

use App\Panel\Http\Respond;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Yalniz PANEL_WEB_ORIGIN ile birebir eslesen origin CORS alir. Kimlik bilgisi (yenileme cerezi)
 * yalniz /api/v1/auth/* altinda izinlidir. Yabanci origin'den gelen durum degistiren istek 403 alir
 * (yenileme cerezi icin CSRF korumasi); Origin basligi olmayan istemciler (Postman, CLI) etkilenmez.
 */
final class PanelCors
{
    private const METHODS = 'GET, POST, PUT, PATCH, DELETE, OPTIONS';

    private const HEADERS = 'Authorization, Content-Type, X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');
        $allowed = config('panel.web_origin');
        $matches = is_string($origin) && is_string($allowed) && $allowed !== '' && hash_equals($allowed, $origin);
        $authPath = str_starts_with('/'.ltrim($request->path(), '/'), '/api/v1/auth/');

        if ($request->isMethod('OPTIONS') && $request->headers->has('Access-Control-Request-Method')) {
            if (! $matches) {
                return Respond::error('Bu kaynaktan erişim izni yok.', 403);
            }

            return $this->decorate(response('', 204, [
                'Access-Control-Allow-Methods' => self::METHODS,
                'Access-Control-Allow-Headers' => self::HEADERS,
                'Access-Control-Max-Age' => '600',
            ]), $origin, $authPath);
        }

        if ($origin !== null && ! $matches && ! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return Respond::error('Bu kaynaktan erişim izni yok.', 403);
        }

        $response = $next($request);

        return $matches ? $this->decorate($response, (string) $origin, $authPath) : $response;
    }

    private function decorate(Response $response, string $origin, bool $credentials): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Expose-Headers', 'X-Request-Id');
        $response->headers->set('Vary', 'Origin', false);
        if ($credentials) {
            $response->headers->set('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }
}
