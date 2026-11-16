<?php

// Hicbir istek veritabanina ulasmaz: yalniz kimlik dogrulamadan once biten yollar denenir.

it('serves health without a database and with panel security headers', function () {
    $r = $this->getJson('/api/health');
    $r->assertOk()->assertExactJson(['data' => ['status' => 'ok']]);
    expect($r->headers->get('Cache-Control'))->toContain('no-store')
        ->and($r->headers->get('X-Frame-Options'))->toBe('DENY')
        ->and($r->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($r->headers->get('X-Request-Id'))->not->toBeEmpty();
});

it('echoes a well-formed request id and replaces a malformed one', function () {
    expect($this->getJson('/api/health', ['X-Request-Id' => 'panel-test-12345'])->headers->get('X-Request-Id'))->toBe('panel-test-12345');
    expect($this->getJson('/api/health', ['X-Request-Id' => 'bad id!'])->headers->get('X-Request-Id'))->not->toBe('bad id!');
});

it('requires a bearer token on every protected route', function (string $method, string $uri) {
    $this->json($method, $uri)->assertStatus(401)->assertExactJson(['error' => 'Giriş gerekli.']);
})->with([
    ['GET', '/api/v1/dashboard'],
    ['GET', '/api/v1/auth/me'],
    ['GET', '/api/v1/businesses'],
    ['POST', '/api/v1/moderation/reviews/abc/decision'],
    ['PUT', '/api/v1/accounts/abc/claims'],
    ['GET', '/api/v1/brand/campaigns'],
]);

it('rejects malformed bearer tokens before touching the database', function () {
    $this->getJson('/api/v1/dashboard', ['Authorization' => 'Bearer not-a-panel-token'])->assertStatus(401);
    $this->getJson('/api/v1/dashboard', ['Authorization' => 'Basic abc'])->assertStatus(401);
});

it('requires JSON and validates login input before any lookup', function () {
    $this->post('/api/v1/auth/login', ['email' => 'a@example.test'])->assertStatus(415)->assertJsonPath('error', 'İçerik türü application/json olmalı.');
    $this->postJson('/api/v1/auth/login', [])->assertStatus(422)->assertJsonStructure(['error', 'fields' => ['email', 'password']]);
    $this->postJson('/api/v1/auth/mfa', ['challengeToken' => 'x', 'code' => '12'])->assertStatus(422)->assertJsonStructure(['fields' => ['challengeToken', 'code']]);
});

it('rejects refresh without a well-formed token', function () {
    $this->postJson('/api/v1/auth/refresh', ['refreshToken' => 'nope'])->assertStatus(401);
});

it('answers CORS preflight only for the configured panel origin', function () {
    $ok = $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], ['HTTP_ORIGIN' => 'https://panel.otosanayide.test', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST']);
    expect($ok->getStatusCode())->toBe(204)
        ->and($ok->headers->get('Access-Control-Allow-Origin'))->toBe('https://panel.otosanayide.test')
        ->and($ok->headers->get('Access-Control-Allow-Credentials'))->toBe('true');
    $api = $this->call('OPTIONS', '/api/v1/dashboard', [], [], [], ['HTTP_ORIGIN' => 'https://panel.otosanayide.test', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET']);
    expect($api->headers->get('Access-Control-Allow-Credentials'))->toBeNull();
    $bad = $this->call('OPTIONS', '/api/v1/auth/login', [], [], [], ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST']);
    expect($bad->getStatusCode())->toBe(403);
});

it('blocks state-changing requests from a foreign origin', function () {
    $this->postJson('/api/v1/auth/refresh', [], ['Origin' => 'https://evil.example'])->assertStatus(403);
});

it('enforces the global IP allowlist', function () {
    config(['panel.ip_allowlist' => '10.0.0.0/8']);
    $this->getJson('/api/v1/dashboard', ['Authorization' => 'Bearer opa_'.str_repeat('a', 40)])->assertStatus(403);
    $this->postJson('/api/v1/auth/login', ['email' => 'a@example.test', 'password' => 'x'])->assertStatus(403);
});

it('returns JSON errors for unknown routes and wrong methods', function () {
    $this->getJson('/api/v1/nope')->assertStatus(404)->assertExactJson(['error' => 'Bulunamadı.']);
    $this->deleteJson('/api/health')->assertStatus(405);
});
