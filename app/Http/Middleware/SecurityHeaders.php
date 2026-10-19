<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds the security headers from config/security.php. The CSP nonce is generated before the view renders
 * so the inline Ziggy script (and Vite tags) can carry it.
 */
class SecurityHeaders
{
    private const PERMISSIONS_POLICY = 'camera=(), microphone=(), geolocation=(), payment=(), usb=()';

    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', self::PERMISSIONS_POLICY);

        // Horizon's dashboard is built on inline scripts and styles, so the app's strict policy would blank it.
        if (config('security.csp.enabled') && ! $request->is(config('horizon.path'), config('horizon.path').'/*')) {
            $name = config('security.csp.report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
            $response->headers->set($name, $this->policy($nonce));
        }

        if ($request->isSecure() && config('security.hsts.enabled')) {
            $response->headers->set('Strict-Transport-Security', 'max-age='.(int) config('security.hsts.max_age').'; includeSubDomains');
        }

        return $response;
    }

    private function policy(string $nonce): string
    {
        $dev = $this->devServer();

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$nonce}'", ...$dev['http']],
            // Vue and Inertia set inline styles at runtime, which a style nonce cannot cover.
            'style-src' => ["'self'", "'unsafe-inline'", 'https://fonts.bunny.net', ...$dev['http']],
            'font-src' => ["'self'", 'https://fonts.bunny.net', 'data:'],
            'img-src' => ["'self'", 'data:'],
            'connect-src' => ["'self'", ...$dev['http'], ...$dev['ws']],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'frame-ancestors' => ["'self'"],
            // Stripe Checkout and the Customer Portal are reached by redirect.
            'form-action' => ["'self'", 'https://checkout.stripe.com', 'https://billing.stripe.com'],
        ];

        foreach ((array) config('security.csp.extra') as $directive => $sources) {
            $directives[$directive] = [...($directives[$directive] ?? ["'self'"]), ...(array) $sources];
        }

        return collect($directives)
            ->map(fn (array $sources, string $directive) => $directive.' '.implode(' ', array_unique($sources)))
            ->implode('; ');
    }

    /** @return array{http: list<string>, ws: list<string>} the Vite HMR origin, only while `npm run dev` is running */
    private function devServer(): array
    {
        if (! Vite::isRunningHot()) {
            return ['http' => [], 'ws' => []];
        }

        $origin = trim((string) file_get_contents(Vite::hotFile()));

        return ['http' => [$origin], 'ws' => [preg_replace('#^http#', 'ws', $origin) ?? $origin]];
    }
}
