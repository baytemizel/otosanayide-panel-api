<?php

use App\Panel\Http\Shape;
use App\Panel\Security\Ip;
use App\Panel\Support\Clock;
use App\Panel\Support\Secret;
use App\Panel\Taxonomy\Taxonomy;

it('matches IPs and CIDRs and treats an empty list as unrestricted', function () {
    expect(Ip::allowed('10.1.2.3', []))->toBeTrue()
        ->and(Ip::allowed('10.1.2.3', ['10.0.0.0/8']))->toBeTrue()
        ->and(Ip::allowed('11.1.2.3', ['10.0.0.0/8']))->toBeFalse()
        ->and(Ip::allowed(null, ['10.0.0.0/8']))->toBeFalse()
        ->and(Ip::validCidr('2001:db8::/32'))->toBeTrue()
        ->and(Ip::validCidr('10.0.0.0/33'))->toBeFalse()
        ->and(Ip::validCidr('panel'))->toBeFalse()
        ->and(Ip::parseList(' 1.1.1.1 , ,2.2.2.0/24'))->toBe(['1.1.1.1', '2.2.2.0/24']);
});

it('hashes IPs with a key and never returns the raw address', function () {
    $h = Ip::hash('203.0.113.9');
    expect($h)->toHaveLength(64)->not->toContain('203.0.113.9');
});

it('normalizes emails like the main API and issues prefixed opaque tokens', function () {
    expect(Secret::normalizeEmail('  ÖZEL@Example.TEST '))->toBe('özel@example.test');
    $t = Secret::token('opa_', 32);
    expect($t)->toStartWith('opa_')->and(strlen($t))->toBe(47)->and(Secret::hash($t))->toHaveLength(64);
});

it('shapes rows to camelCase with ISO times, JSON and booleans', function () {
    $row = Shape::row((object) ['business_id' => 'b1', 'created_at' => '2026-11-16 09:00:00.120', 'details' => '{"a":1}', 'published' => 1, 'secret' => 'x'], ['details'], ['published'], ['secret']);
    expect($row)->toBe(['businessId' => 'b1', 'createdAt' => '2026-11-16T09:00:00.120Z', 'details' => ['a' => 1], 'published' => true]);
    expect(Clock::msIso(0))->toBe('1970-01-01T00:00:00.000Z');
});

it('reads the bundled main API reference data in the expected shape', function () {
    $src = Taxonomy::readSource(resource_path('data'));
    expect($src['catalogue']['categories'])->not->toBeEmpty()
        ->and($src['provinces']['provinces'])->toHaveCount(81)
        ->and(array_diff($src['coverage']['cities'], $src['provinces']['provinces']))->toBe([]);
});

it('keeps the vendored core identical to core-manifest.json', function () {
    $manifest = json_decode((string) file_get_contents(base_path('core-manifest.json')), true);
    expect($manifest['files'])->not->toBeEmpty();
    foreach ($manifest['files'] as $file => $hash) {
        expect(hash_file('sha256', base_path($file)))->toBe($hash, $file);
    }
});
