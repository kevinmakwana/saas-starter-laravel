<?php

use App\Models\Team;
use App\Support\Tenancy\TenantContext;

function teamWithId(int $id): Team
{
    $team = new Team;
    $team->id = $id;

    return $team;
}

it('has no tenant without an override or a signed-in user', function () {
    $context = new TenantContext;

    expect($context->current())->toBeNull()
        ->and($context->id())->toBeNull();
});

it('uses an explicit override and forgets it again', function () {
    $team = teamWithId(7);
    $context = new TenantContext;

    $context->set($team);
    expect($context->current())->toBe($team)->and($context->id())->toBe(7);

    $context->forget();
    expect($context->current())->toBeNull();
});

it('lets an override of null mean "no tenant", not "fall back to the user"', function () {
    $context = new TenantContext;

    $context->set(teamWithId(7));

    expect($context->run(null, fn () => $context->id()))->toBeNull()
        ->and($context->id())->toBe(7);
});

it('restores the previous tenant after run(), nested and when the callback throws', function () {
    $context = new TenantContext;

    $seen = $context->run(teamWithId(1), fn () => [
        $context->id(),
        $context->run(teamWithId(2), fn () => $context->id()),
        $context->id(),
    ]);

    expect($seen)->toBe([1, 2, 1])->and($context->id())->toBeNull();

    expect(fn () => $context->run(teamWithId(1), fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class);
    expect($context->id())->toBeNull();
});
