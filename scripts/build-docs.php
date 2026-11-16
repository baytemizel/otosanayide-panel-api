<?php

/**
 * docs/source/endpoints.json -> docs/postman/*.json ve docs/ENDPOINTS.md.
 * Veritabanina ve aga baglanmaz. Calistirma: composer docs (veya php scripts/build-docs.php).
 * Rota eslesmesi tests/Feature/RouteCatalogTest.php ile denetlenir.
 */
$root = dirname(__DIR__);
$spec = json_decode((string) file_get_contents($root.'/docs/source/endpoints.json'), true, 64, JSON_THROW_ON_ERROR);
$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

$uuid = static function (string $seed): string {
    $h = md5('otosanayide-panel-api:'.$seed);

    return sprintf('%s-%s-4%s-a%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 17, 3), substr($h, 20, 12));
};

// Ornek degerler: yol degiskenleri ve govdedeki {{...}} yer tutuculari. Gizli deger (sifre, jeton) bos kalir.
$defaults = [
    'baseUrl' => $spec['info']['baseUrl'], 'panelEmail' => '', 'panelPassword' => '', 'accessToken' => '', 'refreshToken' => '', 'challengeToken' => '',
    'settingKey' => 'support_access.max_minutes', 'key' => 'catalogue_reader', 'aliasKind' => 'alias', 'aliasTerm' => 'egzozcu',
    'cityName' => 'Ankara', 'categoryId' => 'glass',
];
$vars = [];
$collect = static function (string $text) use (&$vars): void {
    preg_match_all('/\{\{([A-Za-z]+)\}\}/', $text, $m);
    foreach ($m[1] as $v) {
        $vars[$v] = true;
    }
};

$groups = [];
foreach ($spec['groups'] as $g) {
    $groups[$g['key']] = ['name' => $g['name'], 'item' => []];
}
$md = [];
foreach ($spec['endpoints'] as $e) {
    $auth = $e['auth'] ?? true;
    $segments = array_values(array_filter(explode('/', $e['path']), static fn (string $s): bool => $s !== ''));
    $pathVars = [];
    foreach ($segments as $i => $s) {
        if (preg_match('/^\{([A-Za-z]+)\}$/', $s, $m) === 1) {
            $segments[$i] = ':'.$m[1];
            $pathVars[] = ['key' => $m[1], 'value' => '{{'.$m[1].'}}'];
            $vars[$m[1]] = true;
        }
    }
    $query = [];
    foreach ($e['query'] ?? [] as [$k, $v, $d]) {
        $query[] = ['key' => $k, 'value' => $v, 'description' => $d] + ($v === '' ? ['disabled' => true] : []);
        $collect($v);
    }
    $enabled = array_filter($query, static fn (array $q): bool => ! ($q['disabled'] ?? false));
    $raw = '{{baseUrl}}/'.implode('/', $segments).($enabled === [] ? '' : '?'.implode('&', array_map(static fn (array $q): string => $q['key'].'='.$q['value'], $enabled)));
    $perm = $e['perm'] ?? null;
    $description = $e['desc'].($auth ? "\n\nYetki: ".($perm === null ? 'giriş yeterli' : '`'.str_replace('|', '` veya `', $perm).'`') : "\n\nKimlik doğrulama gerekmez.");
    $request = [
        'method' => $e['method'],
        'header' => array_values(array_filter([
            ['key' => 'Accept', 'value' => 'application/json'],
            isset($e['body']) ? ['key' => 'Content-Type', 'value' => 'application/json'] : null,
        ])),
        'url' => array_filter(['raw' => $raw, 'host' => ['{{baseUrl}}'], 'path' => $segments, 'query' => $query ?: null, 'variable' => $pathVars ?: null], static fn ($v) => $v !== null),
        'description' => $description,
    ];
    if (! $auth) {
        $request['auth'] = ['type' => 'noauth'];
    }
    if (isset($e['body'])) {
        $body = json_encode($e['body'], $flags);
        $collect($body);
        $request['body'] = ['mode' => 'raw', 'raw' => $body, 'options' => ['raw' => ['language' => 'json']]];
    }
    $item = ['name' => $e['name'], 'request' => $request, 'response' => []];
    if (isset($e['saves'])) {
        $lines = ['const res = pm.response.json();', "pm.test('Durum 200', () => pm.response.to.have.status(200));", 'if (res && res.data) {'];
        foreach ($e['saves'] as $var => $path) {
            $js = 'res.'.$path;
            $lines[] = "  if ({$js} !== undefined) { pm.collectionVariables.set('{$var}', {$js}); }";
        }
        $lines[] = '}';
        $item['event'] = [['listen' => 'test', 'script' => ['type' => 'text/javascript', 'exec' => $lines]]];
    }
    $groups[$e['group']]['item'][] = $item;
    $md[$e['group']][] = $e;
}

ksort($vars);
$variables = [];
foreach (array_unique(array_merge(array_keys($defaults), array_keys($vars))) as $v) {
    $variables[] = ['key' => $v, 'value' => $defaults[$v] ?? ''];
}

$collection = [
    'info' => [
        '_postman_id' => $uuid('collection'),
        'name' => $spec['info']['title'].' '.$spec['info']['version'],
        'description' => $spec['info']['description']."\n\n1) Ortam dosyasında baseUrl, panelEmail, panelPassword doldurun.\n2) 01 › Giriş → challengeToken kaydedilir.\n3) 01 › MFA doğrulama (TOTP kodunu yazın) → accessToken ve refreshToken kaydedilir.\n4) Diğer istekler koleksiyon düzeyindeki Bearer {{accessToken}} ile gider. Erişim jetonu 15 dakikada dolar: 01 › Oturum yenileme.\n\nPostman Origin göndermediği için yenileme jetonu gövdede döner (tarayıcıda yalnız HttpOnly çerez).",
        'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
    ],
    'auth' => ['type' => 'bearer', 'bearer' => [['key' => 'token', 'value' => '{{accessToken}}', 'type' => 'string']]],
    'variable' => $variables,
    'item' => array_values(array_filter($groups, static fn (array $g): bool => $g['item'] !== [])),
];
@mkdir($root.'/docs/postman', 0755, true);
file_put_contents($root.'/docs/postman/otosanayide-panel-api.postman_collection.json', json_encode($collection, $flags)."\n");

$environment = [
    'id' => $uuid('environment-local'),
    'name' => 'Otosanayide Panel API · yerel',
    'values' => [
        ['key' => 'baseUrl', 'value' => $spec['info']['baseUrl'], 'type' => 'default', 'enabled' => true],
        ['key' => 'panelEmail', 'value' => '', 'type' => 'default', 'enabled' => true],
        ['key' => 'panelPassword', 'value' => '', 'type' => 'secret', 'enabled' => true],
        ['key' => 'accessToken', 'value' => '', 'type' => 'secret', 'enabled' => true],
        ['key' => 'refreshToken', 'value' => '', 'type' => 'secret', 'enabled' => true],
        ['key' => 'challengeToken', 'value' => '', 'type' => 'secret', 'enabled' => true],
    ],
    '_postman_variable_scope' => 'environment',
];
file_put_contents($root.'/docs/postman/otosanayide-panel-api.local.postman_environment.json', json_encode($environment, $flags)."\n");

// ENDPOINTS.md -----------------------------------------------------------------------------------
$out = ['# Uç referansı', '', '> Bu dosya `docs/source/endpoints.json` kaynağından `composer docs` ile üretilir; elle düzenlemeyin.', '', 'Toplam **'.count($spec['endpoints']).'** uç. Yanıt zarfı, hata biçimi, oturum ve yetki kuralları için [API.md](API.md).', ''];
foreach ($spec['groups'] as $g) {
    if (! isset($md[$g['key']])) {
        continue;
    }
    $out[] = '## '.$g['name'];
    $out[] = '';
    $out[] = '| Yöntem | Yol | Yetki | Açıklama |';
    $out[] = '|---|---|---|---|';
    foreach ($md[$g['key']] as $e) {
        $perm = ($e['auth'] ?? true) ? ($e['perm'] ?? null ? '`'.str_replace('|', '` / `', $e['perm']).'`' : 'giriş') : 'yok';
        $out[] = '| '.$e['method'].' | `'.$e['path'].'` | '.$perm.' | '.str_replace(["\n", '|'], [' ', '\|'], $e['name']).' |';
    }
    $out[] = '';
    foreach ($md[$g['key']] as $e) {
        $out[] = '### '.$e['method'].' `'.$e['path'].'`';
        $out[] = '';
        $out[] = '**'.$e['name'].'.** '.$e['desc'];
        $out[] = '';
        if (! empty($e['query'])) {
            $out[] = '| Sorgu | Örnek | Açıklama |';
            $out[] = '|---|---|---|';
            foreach ($e['query'] as [$k, $v, $d]) {
                $out[] = '| `'.$k.'` | '.($v === '' ? '' : '`'.str_replace('|', '\|', $v).'`').' | '.str_replace('|', '\|', $d).' |';
            }
            $out[] = '';
        }
        if (isset($e['body'])) {
            $out[] = '```json';
            $out[] = json_encode($e['body'], $flags);
            $out[] = '```';
            $out[] = '';
        }
    }
}
file_put_contents($root.'/docs/ENDPOINTS.md', implode("\n", $out));

fwrite(STDOUT, sprintf("Uç: %d · Postman klasörü: %d · değişken: %d\n", count($spec['endpoints']), count($collection['item']), count($variables)));
