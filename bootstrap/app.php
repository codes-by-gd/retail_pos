<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Modules\Inventory\Services\InsufficientStockException;
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
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SecurityHeaders::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Business-rule violations (e.g. not enough stock) go back to the form with a message.
        $exceptions->render(function (DomainException|InsufficientStockException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->withInput()->with('error', $e->getMessage());
        });

        // Production: show errors as an app page instead of raw HTML inside the Inertia window.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();
            if ($status === 419) {
                return back()->with('error', 'The page expired — please try again.');
            }
            if (! app()->environment(['local', 'testing']) && in_array($status, [403, 404, 500, 503], true) && ! $request->expectsJson()) {
                return Inertia::render('Error', ['status' => $status, 'message' => $status === 403 ? ($e->getMessage() ?: null) : null])
                    ->toResponse($request)->setStatusCode($status);
            }

            return $response;
        });
    })->create();
