<?php

use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            EnsureUserIsActive::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['api_key', 'api_salt']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            $status = $response->getStatusCode();
            $handled = [403, 404, 419];

            if (app()->isProduction()) {
                $handled = [...$handled, 500, 503];
            }

            if (! in_array($status, $handled, true) || $request->is('api/*') || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
                return $response;
            }

            $messages = [
                403 => 'Недостаточно прав для этого действия.',
                404 => 'Запись не найдена или недоступна.',
                419 => 'Сессия устарела. Обновите страницу и повторите.',
                500 => 'Не удалось выполнить действие. Попробуйте ещё раз.',
                503 => 'Сервис временно недоступен.',
            ];

            if (! $request->isMethod('GET') && $request->header('X-Inertia')) {
                Inertia::flash('toast', ['type' => 'error', 'message' => $messages[$status]]);

                return back(303);
            }

            return Inertia::render('error', ['status' => $status, 'message' => $messages[$status]])
                ->toResponse($request)
                ->setStatusCode($status);
        });
    })->create();
