<?php

namespace App\Panel\Http;

use App\Exceptions\HttpError;
use App\Support\Body;
use App\Support\Query;
use Illuminate\Http\Request;
use stdClass;

/**
 * Vendor edilen cekirdek uclarina giris: govde ham JSON olarak okunur (Body::json) ve yol parametreleri
 * ayni alana eklenir. Govdede ayni alan farkli degerle gelirse 400; boylece sema tek kaynaktan dogrular.
 */
final class DomainInput
{
    public static function requireJson(Request $request): void
    {
        if (preg_match('/^application\/json(?:\s*;|$)/iD', (string) $request->headers->get('Content-Type')) !== 1) {
            throw new HttpError(415, 'İçerik türü application/json olmalı.');
        }
    }

    /** @param array<string,mixed> $path */
    public static function body(Request $request, array $path = []): stdClass
    {
        self::requireJson($request);
        $body = Body::json($request);
        if (! $body instanceof stdClass) {
            throw new HttpError(400, 'Gövde bir JSON nesnesi olmalı.');
        }
        foreach ($path as $key => $value) {
            if (property_exists($body, $key) && $body->{$key} !== $value) {
                throw new HttpError(400, 'Gövdedeki '.$key.' adresle aynı olmalı.');
            }
            $body->{$key} = $value;
        }

        return $body;
    }

    /**
     * Sorgu dizesini tekrar eden anahtar olmadan nesneye cevirir (ana API Gate::params).
     *
     * @param  list<string>  $allowed  bos: hepsi
     */
    public static function query(Request $request, array $allowed = []): stdClass
    {
        $q = Query::from($request);
        $out = new stdClass;
        foreach ($q->keys() as $key) {
            if ($q->count($key) !== 1 || ($allowed !== [] && ! in_array($key, $allowed, true))) {
                throw new HttpError(400, 'Geçersiz istek.');
            }
            $out->{$key} = $q->get($key);
        }

        return $out;
    }

    public static function reason(Request $request, int $min = 10): string
    {
        $reason = $request->input('reason');
        if (! is_string($reason) || mb_strlen(trim($reason)) < $min || mb_strlen($reason) > 1000) {
            throw new HttpError(400, 'Gerekçe '.$min.'-1000 karakter olmalı.');
        }

        return trim($reason);
    }
}
