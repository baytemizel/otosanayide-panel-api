<?php

use Illuminate\Support\Facades\Route;

// Rota tablosu ile docs/source/endpoints.json birebir esit olmali: dokumantasyon ve Postman bundan uretilir.

function specEndpoints(): array
{
    return json_decode((string) file_get_contents(base_path('docs/source/endpoints.json')), true)['endpoints'];
}

function normalizePath(string $path): string
{
    return preg_replace('/\{[^}]+\}/', '{}', '/'.ltrim($path, '/'));
}

/** @return array<string,array{perm:?string,middleware:list<string>}> */
function registeredRoutes(): array
{
    $out = [];
    foreach (Route::getRoutes() as $route) {
        foreach ($route->methods() as $method) {
            if ($method === 'HEAD') {
                continue;
            }
            $mw = $route->gatherMiddleware();
            $perm = collect($mw)->first(fn ($m) => is_string($m) && str_starts_with($m, 'perm:'));
            $out[$method.' '.normalizePath($route->uri())] = ['perm' => $perm === null ? null : substr($perm, 5), 'middleware' => array_values(array_filter($mw, 'is_string'))];
        }
    }

    return $out;
}

it('documents every registered route and nothing else', function () {
    $documented = array_map(fn (array $e) => $e['method'].' '.normalizePath($e['path']), specEndpoints());
    expect(count($documented))->toBe(count(array_unique($documented)));
    $registered = array_keys(registeredRoutes());
    expect(array_values(array_diff($registered, $documented)))->toBe([])
        ->and(array_values(array_diff($documented, $registered)))->toBe([]);
});

it('documents the same permission the route enforces', function () {
    $routes = registeredRoutes();
    foreach (specEndpoints() as $e) {
        $key = $e['method'].' '.normalizePath($e['path']);
        expect($routes[$key]['perm'])->toBe(($e['auth'] ?? true) ? ($e['perm'] ?? null) : null, $key);
    }
});

it('protects every route except health and the four sign-in steps', function () {
    $public = ['GET /api/health', 'POST /api/v1/auth/login', 'POST /api/v1/auth/mfa', 'POST /api/v1/auth/refresh', 'POST /api/v1/auth/logout'];
    $permless = ['GET /api/v1/auth/me', 'GET /api/v1/auth/sessions', 'DELETE /api/v1/auth/sessions/{}', 'POST /api/v1/approvals/{}/cancel'];
    foreach (registeredRoutes() as $key => $r) {
        if (in_array($key, $public, true)) {
            expect($r['middleware'])->not->toContain('panel');

            continue;
        }
        expect($r['middleware'])->toContain('panel');
        if (! in_array($key, $permless, true)) {
            expect($r['perm'])->not->toBeNull($key);
        }
    }
});

it('ships a generated Postman collection that matches the catalogue', function () {
    $c = json_decode((string) file_get_contents(base_path('docs/postman/otosanayide-panel-api.postman_collection.json')), true);
    $count = array_sum(array_map(fn (array $g) => count($g['item']), $c['item']));
    expect($c['info']['schema'])->toContain('v2.1.0')->and($count)->toBe(count(specEndpoints()));
    $vars = array_column($c['variable'], 'value', 'key');
    expect($vars['panelPassword'])->toBe('')->and($vars['accessToken'])->toBe('');
});
