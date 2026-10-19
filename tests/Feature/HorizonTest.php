<?php

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;

beforeEach(fn () => config(['horizon.allowed_emails' => ['ops@example.com']]));

it('keeps the dashboard away from guests', function () {
    $this->get('/horizon')->assertForbidden();
});

it('keeps the dashboard away from users who are not on the allow list', function () {
    $this->actingAs(User::factory()->create(['email' => 'someone@example.com']))->get('/horizon')->assertForbidden();
});

it('lets allow-listed users in, whatever the email casing', function () {
    config(['horizon.allowed_emails' => ['Ops@Example.com']]);

    $this->actingAs(User::factory()->create(['email' => 'ops@example.com']))->get('/horizon')->assertOk();
});

it('keeps out someone who signed up with an allow-listed address but never proved they own it', function () {
    $impostor = User::factory()->unverified()->create(['email' => 'ops@example.com']);

    $this->actingAs($impostor)->get('/horizon')->assertForbidden();
});

it('lets nobody in when the allow list is empty', function () {
    config(['horizon.allowed_emails' => []]);

    $this->actingAs(User::factory()->create(['email' => 'ops@example.com']))->get('/horizon')->assertForbidden();
});

it('sends the hardening headers but not the app CSP on the dashboard, which needs inline scripts', function () {
    $response = $this->actingAs(User::factory()->create(['email' => 'ops@example.com']))->get('/horizon')->assertOk();

    expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN')
        ->and($response->headers->get('Content-Security-Policy'))->toBeNull();
});

it('still sends the CSP on app pages', function () {
    $this->get('/login')->assertHeader('Content-Security-Policy');
});

it('runs workers for every queue the app uses, retrying like the old worker did', function () {
    $supervisor = config('horizon.defaults.supervisor-1');

    expect($supervisor['connection'])->toBe('redis')
        ->and($supervisor['queue'])->toEqualCanonicalizing(['default', 'webhooks', 'mail'])
        ->and($supervisor['tries'])->toBe(3);
});

it('schedules the metrics snapshot every five minutes', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'horizon:snapshot'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('*/5 * * * *');
});
