<?php

namespace App\Panel\Http;

use Illuminate\Http\JsonResponse;

/**
 * Basarili yanit zarfi: {"data": ...} ve listelerde {"meta": ...}.
 * Hata zarfi PanelExceptionRenderer'dadir: {"error": "..."} (+ dogrulamada "fields").
 */
final class Respond
{
    public const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    /** @param array<string,mixed> $meta */
    public static function data(mixed $data, int $status = 200, array $meta = []): JsonResponse
    {
        $body = ['data' => $data];
        if ($meta !== []) {
            $body['meta'] = $meta;
        }

        return new JsonResponse($body, $status, [], self::FLAGS);
    }

    public static function created(mixed $data): JsonResponse
    {
        return self::data($data, 201);
    }

    /** Ikinci kontrole dusen islem: 202 ve bekleyen talep. */
    public static function pendingApproval(array $approval): JsonResponse
    {
        return self::data(['approvalRequired' => true, 'approval' => $approval], 202);
    }

    /** @param list<mixed> $items */
    public static function page(array $items, Paging $paging, int $total): JsonResponse
    {
        return self::data($items, 200, ['page' => $paging->page, 'perPage' => $paging->perPage, 'total' => $total, 'lastPage' => max(1, (int) ceil($total / $paging->perPage))]);
    }

    public static function error(string $message, int $status, array $extra = []): JsonResponse
    {
        return new JsonResponse(['error' => $message] + $extra, $status, ['Cache-Control' => 'no-store'], self::FLAGS);
    }
}
