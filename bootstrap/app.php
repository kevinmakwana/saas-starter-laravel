<?php

use App\Http\Middleware\EnsureHasTeam;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireFeature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'team' => EnsureHasTeam::class,
            'feature' => RequireFeature::class,
        ]);
        $middleware->validateCsrfTokens(except: ['webhooks/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Plan-limit denials (402) become a friendly upgrade prompt instead of a bare error page.
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() === 402 && ($request->header('X-Inertia') || ! $request->expectsJson())) {
                // A denied page view (GET) cannot go "back" to itself without looping: send it to the plans page.
                return $request->isMethod('GET')
                    ? redirect()->route('billing.show')->with('upgrade', $e->getMessage())
                    : back()->with('upgrade', $e->getMessage());
            }
        });

        // Throttled Inertia requests get a banner with the retry time instead of an error page.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->header('X-Inertia')) {
                $seconds = (int) ($e->getHeaders()['Retry-After'] ?? 60);

                return back()->with('error', "Too many attempts. Please try again in {$seconds} seconds.");
            }
        });
    })->create();
