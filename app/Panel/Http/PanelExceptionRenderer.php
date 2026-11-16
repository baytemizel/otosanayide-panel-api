<?php

namespace App\Panel\Http;

use App\Domain\Admin\ClaimReviewConflict;
use App\Exceptions\HttpError;
use App\Exceptions\WriteConflict;
use App\Panel\Auth\LoginFailed;
use App\Panel\Auth\MfaFailed;
use App\Panel\Auth\RefreshReused;
use App\Support\ZodError;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/** Tek hata bicimi: {"error":"..."}; dogrulamada {"error","fields"}; 500'de {"error","requestId"}. */
final class PanelExceptionRenderer
{
    private const GENERIC = 'İşlem tamamlanamadı. Daha sonra tekrar deneyin.';

    public static function render(Throwable $e, Request $request): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return Respond::error('Bilgileri kontrol edin.', 422, ['fields' => $e->errors()]);
        }
        if ($e instanceof ZodError) {
            // Vendor edilen cekirdek semasi: ana API ile ayni ileti ve 400.
            return Respond::error($e->issue === 'custom' && $e->getMessage() !== '' ? $e->getMessage() : 'Bilgileri kontrol edin.', 400);
        }
        if ($e instanceof HttpError) {
            return Respond::error($e->getMessage(), $e->status);
        }
        if ($e instanceof LoginFailed || $e instanceof MfaFailed || $e instanceof RefreshReused) {
            return Respond::error('Oturum açılamadı.', 401);
        }
        if ($e instanceof ClaimReviewConflict) {
            return Respond::error($e->getMessage(), 409);
        }
        if ($e instanceof WriteConflict) {
            return Respond::error('Kayıt değişti veya hesap işlem altında. Yenileyip tekrar deneyin.', 409);
        }
        if ($e instanceof UniqueConstraintViolationException) {
            return Respond::error('Kayıt zaten var veya aynı anda değişti.', 409);
        }
        if ($e instanceof PostTooLargeException) {
            return Respond::error('İstek çok büyük.', 413);
        }
        if ($e instanceof ThrottleRequestsException) {
            return Respond::error('Çok fazla işlem. Bir dakika sonra yeniden deneyin.', 429);
        }
        if ($e instanceof NotFoundHttpException) {
            return Respond::error('Bulunamadı.', 404);
        }
        if ($e instanceof MethodNotAllowedHttpException) {
            return Respond::error('Bu adres bu yöntemi desteklemiyor.', 405)->withHeaders($e->getHeaders());
        }
        if ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 503) {
            return Respond::error('Bakım nedeniyle geçici olarak kapalı.', 503);
        }
        $requestId = (string) $request->attributes->get('request_id', '');
        if ($e instanceof QueryException) {
            // Ileti bagli degerleri (kisisel veri) tasiyabilir: yalniz sinif ve SQLSTATE yazilir.
            Log::error('Panel query failed', ['class' => $e::class, 'sqlstate' => $e->errorInfo[0] ?? null, 'requestId' => $requestId]);

            return Respond::error('Veritabanı işlemi tamamlanamadı.', 503, ['requestId' => $requestId]);
        }
        Log::error('Panel request failed', ['class' => $e::class, 'requestId' => $requestId]);

        return Respond::error(self::GENERIC, 500, ['requestId' => $requestId]);
    }
}
