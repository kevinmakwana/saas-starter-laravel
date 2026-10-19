<?php

use App\Support\TrustedProxies;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;

function cspOf($response): string
{
    return (string) $response->headers->get('Content-Security-Policy');
}

it('sends hardening headers on web responses', function () {
    $response = $this->get('/login')->assertOk();

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($response->headers->get('Referrer-Policy'))->toBe('strict-origin-when-cross-origin')
        ->and($response->headers->get('Permissions-Policy'))->toContain('camera=()')->toContain('geolocation=()');
});

it('also sets them on error and redirect responses', function () {
    $this->get('/no-such-page')->assertNotFound()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get('/dashboard')->assertRedirect()->assertHeader('X-Frame-Options', 'SAMEORIGIN');
});

it('sets a strict content security policy', function () {
    $csp = cspOf($this->get('/login'));

    expect($csp)->toContain("default-src 'self'")
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'self'")
        ->toContain("frame-ancestors 'self'")
        ->toContain('https://checkout.stripe.com')
        ->not->toContain("'unsafe-eval'")
        ->not->toMatch("/script-src[^;]*'unsafe-inline'/");
});

it('gives the inline Ziggy script the nonce from the policy', function () {
    $response = $this->get('/login');

    preg_match("/'nonce-([^']+)'/", cspOf($response), $matches);

    expect($matches)->not->toBeEmpty()
        ->and($response->getContent())->toContain('nonce="'.$matches[1].'"');
});

it('uses a fresh nonce for every response', function () {
    preg_match("/'nonce-([^']+)'/", cspOf($this->get('/login')), $first);
    preg_match("/'nonce-([^']+)'/", cspOf($this->get('/login')), $second);

    expect($first[1])->not->toBe($second[1]);
});

it('only sends HSTS over https', function () {
    $this->get('http://localhost/login')->assertHeaderMissing('Strict-Transport-Security');

    expect($this->get('https://localhost/login')->headers->get('Strict-Transport-Security'))
        ->toBe('max-age=31536000; includeSubDomains');
});

it('can turn HSTS off', function () {
    config(['security.hsts.enabled' => false]);

    $this->get('https://localhost/login')->assertHeaderMissing('Strict-Transport-Security');
});

it('can run the policy in report-only mode', function () {
    config(['security.csp.report_only' => true]);

    $response = $this->get('/login');

    expect($response->headers->get('Content-Security-Policy'))->toBeNull()
        ->and($response->headers->get('Content-Security-Policy-Report-Only'))->toContain("default-src 'self'");
});

it('can turn the policy off', function () {
    config(['security.csp.enabled' => false]);

    $this->get('/login')->assertHeaderMissing('Content-Security-Policy');
});

it('allows the Vite dev server only while it is running hot', function () {
    expect(cspOf($this->get('/login')))->not->toContain('localhost:5173');

    $hot = storage_path('framework/testing/hot');
    file_put_contents($hot, 'http://localhost:5173');
    Vite::useHotFile($hot);

    try {
        $csp = cspOf($this->get('/login'));
    } finally {
        unlink($hot);
    }

    expect($csp)->toContain('http://localhost:5173')->toContain('ws://localhost:5173');
});

it('applies to routes registered outside the main group', function () {
    Route::middleware('web')->get('/_probe', fn () => 'ok');

    $this->get('/_probe')->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('lets form posts and links reach Stripe Checkout and the Customer Portal', function () {
    $policy = (string) $this->get('/login')->headers->get('Content-Security-Policy');

    expect($policy)->toContain('https://checkout.stripe.com')->toContain('https://billing.stripe.com');
});

describe('behind a TLS-terminating load balancer', function () {
    afterEach(fn () => TrustProxies::flushState());

    it('sees plain http when the proxy is not trusted, so it cannot tell the request was https', function () {
        $this->get('http://localhost/login', ['X-Forwarded-Proto' => 'https'])->assertHeaderMissing('Strict-Transport-Security');
    });

    it('sends HSTS once the proxy is trusted', function () {
        TrustProxies::at('*');

        $this->get('http://localhost/login', ['X-Forwarded-Proto' => 'https'])->assertHeader('Strict-Transport-Security');
    });
});

it('reads TRUSTED_PROXIES as nothing, everything, or a list', function (?string $env, array|string|null $expected) {
    expect(TrustedProxies::parse($env))->toBe($expected);
})->with([
    'unset' => [null, null],
    'empty' => ['', null],
    'everything' => ['*', '*'],
    'a list' => ['10.0.0.1, 172.16.0.0/12', ['10.0.0.1', '172.16.0.0/12']],
    'stray commas' => [' 10.0.0.1 ,, ', ['10.0.0.1']],
]);
