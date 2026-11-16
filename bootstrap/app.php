<?php

use App\Exceptions\HttpError;
use App\Http\Middleware\PanelAuthenticate;
use App\Http\Middleware\PanelCors;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrustProxies;
use App\Panel\Http\PanelExceptionRenderer;
use App\Support\ZodError;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // TrimStrings/ConvertEmptyStringsToNull yok: vendor edilen cekirdek ham govdeyi kendisi dogrular.
        $middleware->use([
            TrustProxies::class,
            SecurityHeaders::class,
            PanelCors::class,
            PreventRequestsDuringMaintenance::class,
            ValidatePostSize::class,
        ]);
        $middleware->group('api', []);
        $middleware->alias([
            'panel' => PanelAuthenticate::class,
            'perm' => RequirePermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([HttpError::class, ZodError::class, ValidationException::class]);
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => true);
        $exceptions->render(fn (Throwable $e, Request $request) => PanelExceptionRenderer::render($e, $request));
    })->create();
