<?php

/** The theme is chosen in the browser, but the page has to apply it before anything is painted. */
it('applies the saved or system theme from a script in the head that the CSP allows', function () {
    $response = $this->get('/login')->assertOk();
    $html = $response->getContent();

    preg_match("/'nonce-([^']+)'/", (string) $response->headers->get('Content-Security-Policy'), $policy);
    preg_match('#<script nonce="([^"]+)">([^<]*)</script>#', $html, $inline);

    expect($policy)->not->toBeEmpty()
        ->and($inline)->not->toBeEmpty()
        ->and($inline[1])->toBe($policy[1])
        ->and($inline[2])->toContain("localStorage.getItem('theme')")->toContain('prefers-color-scheme: dark')->toContain("classList.add('dark')");
});

it('puts the theme script before the stylesheet and the app scripts, so there is no flash of the wrong theme', function () {
    $html = $this->get('/login')->getContent();

    $theme = strpos($html, "localStorage.getItem('theme')");
    $firstAsset = min(array_filter([strpos($html, '<link rel="stylesheet"'), strpos($html, '<script type="module"')], fn ($p) => $p !== false));

    expect($theme)->not->toBeFalse()->and($theme)->toBeLessThan($firstAsset);
});

it('tells the browser both themes are supported, so form controls and scrollbars follow the page', function () {
    $this->get('/login')->assertSee('<meta name="color-scheme" content="light dark">', false);
});
