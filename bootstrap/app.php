<?php

use App\Http\Middleware\AddRequestContext;
use App\Http\Middleware\EnsureHasTeam;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireFeature;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\UseTokenTeam;
use App\Support\TrustedProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a load balancer that ends TLS, the app only knows a request was https (for HSTS and signed
        // links) by trusting that proxy. Nothing is trusted unless TRUSTED_PROXIES says so.
        $middleware->trustProxies(at: TrustedProxies::parse(env('TRUSTED_PROXIES')));

        // Global, not just `web`: unmatched URLs (404s) never enter the web group.
        $middleware->append(SecurityHeaders::class);

        $middleware->web(append: [
            AddRequestContext::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'team' => EnsureHasTeam::class,
            'feature' => RequireFeature::class,
            'token.team' => UseTokenTeam::class,
            'abilities' => CheckAbilities::class,
        ]);
        // The token's team must be the tenant before route parameters (projects, tasks) are looked up.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: UseTokenTeam::class);
        $middleware->validateCsrfTokens(except: ['webhooks/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Reports unhandled exceptions to Sentry. A no-op until SENTRY_LARAVEL_DSN is set.
        Integration::handles($exceptions);

        // The API always answers in JSON, even to a client that forgot to ask for it.
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        // Plan-limit denials (402) become a friendly upgrade prompt instead of a bare error page.
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() === 402 && ! $request->is('api/*') && ($request->header('X-Inertia') || ! $request->expectsJson())) {
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
