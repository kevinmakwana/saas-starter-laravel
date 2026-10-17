<?php

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\State\Scope;

beforeEach(function () {
    Route::middleware(['web', 'auth', 'team'])->get('/_context', fn () => response()->json(Context::all()));
    Route::middleware('web')->get('/_guest-context', fn () => response()->json(Context::all()));
});

it('stamps every response with a request id', function () {
    $id = $this->get('/login')->headers->get('X-Request-Id');

    expect($id)->toMatch('/^[0-9a-f-]{36}$/');
    expect($this->get('/login')->headers->get('X-Request-Id'))->not->toBe($id);
});

it('keeps a sane request id sent by a load balancer', function () {
    $this->withHeader('X-Request-Id', 'abc-123_DEF')->get('/login')->assertHeader('X-Request-Id', 'abc-123_DEF');
});

it('replaces a request id that could pollute logs', function (string $bad) {
    $id = $this->withHeader('X-Request-Id', $bad)->get('/login')->headers->get('X-Request-Id');

    expect($id)->not->toBe($bad)->toMatch('/^[0-9a-f-]{36}$/');
})->with([
    'too long' => [str_repeat('a', 65)],
    'newline' => ["abc\ndef"],
    'spaces and symbols' => ['a b<script>'],
]);

it('adds the user and team to the log context, without personal data', function () {
    $user = ownerWithTeam();

    $context = $this->actingAs($user)->get('/_context')->json();

    expect($context)->toMatchArray(['user_id' => $user->id, 'team_id' => $user->current_team_id])
        ->and($context['request_id'])->toBeString()
        ->and(json_encode($context))->not->toContain($user->email)->not->toContain($user->name);
});

it('only has the request id for guests', function () {
    $context = $this->get('/_guest-context')->json();

    expect($context)->toHaveKey('request_id')->not->toHaveKey('user_id')->not->toHaveKey('team_id');
});

it('does not leak one request context into the next', function () {
    $user = ownerWithTeam();
    $this->actingAs($user)->get('/_context');

    $this->app['auth']->forgetGuards();
    $guest = $this->get('/_guest-context')->json();

    expect($guest)->not->toHaveKey('user_id');
});

describe('Sentry scope', function () {
    /** The tags and user that an error reported right now would carry. */
    function sentryScope(): Event
    {
        $event = Event::createEvent();
        SentrySdk::getCurrentHub()->configureScope(function (Scope $scope) use (&$event) {
            $event = $scope->applyToEvent($event) ?? $event;
        });

        return $event;
    }

    beforeEach(fn () => SentrySdk::getCurrentHub()->configureScope(fn (Scope $scope) => $scope->clear()));

    it('tags errors with the request, user and team ids', function () {
        $user = ownerWithTeam();

        $this->actingAs($user)->get('/_context');
        $event = sentryScope();

        expect($event->getTags())->toMatchArray(['team_id' => (string) $user->current_team_id])
            ->and($event->getTags()['request_id'])->toBeString()
            ->and($event->getUser()?->getId())->toBe((string) $user->id);
    });

    it('does not send the email or name to Sentry', function () {
        $user = ownerWithTeam();

        $this->actingAs($user)->get('/_context');
        $event = sentryScope();

        expect($event->getUser()?->getEmail())->toBeNull()
            ->and($event->getUser()?->getUsername())->toBeNull()
            ->and(json_encode($event->getTags()))->not->toContain($user->email);
    });

    it('is disabled until a DSN is configured', function () {
        expect(config('sentry.dsn'))->toBeNull();
    });
});
