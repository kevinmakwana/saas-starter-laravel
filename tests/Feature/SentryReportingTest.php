<?php

use Illuminate\Support\Facades\Route;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\SentrySdk;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

/** Collects events instead of sending them, so nothing leaves the test. */
class SpyTransport implements TransportInterface
{
    /** @var list<Event> */
    public array $events = [];

    public function send(Event $event): Result
    {
        $this->events[] = $event;

        return new Result(ResultStatus::success(), $event);
    }

    public function close(?int $timeout = null): Result
    {
        return new Result(ResultStatus::success());
    }
}

beforeEach(function () {
    $this->transport = new SpyTransport;
    $client = ClientBuilder::create(['dsn' => 'https://public@o0.ingest.sentry.io/1'])
        ->setTransport($this->transport)
        ->getClient();
    SentrySdk::getCurrentHub()->bindClient($client);

    Route::middleware(['web', 'auth', 'team'])->get('/_boom', fn () => throw new RuntimeException('Something broke'));
});

it('reports unhandled exceptions to Sentry with the request and team ids', function () {
    $user = ownerWithTeam();

    $this->actingAs($user)->get('/_boom')->assertStatus(500);

    expect($this->transport->events)->toHaveCount(1);
    $event = $this->transport->events[0];
    expect($event->getExceptions()[0]->getValue())->toBe('Something broke')
        ->and($event->getTags())->toMatchArray(['team_id' => (string) $user->current_team_id])
        ->and($event->getTags())->toHaveKey('request_id');
});

it('does not report ordinary 404s and validation errors', function () {
    $this->get('/no-such-page')->assertNotFound();
    $this->post('/login', [])->assertSessionHasErrors();

    expect($this->transport->events)->toBe([]);
});
