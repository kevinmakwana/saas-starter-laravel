<?php

use App\Models\Project;
use App\Models\Team;
use App\Support\Tenancy\TenantContext;

it('matches nothing when there is no tenant', function () {
    expect(Project::query()->toSql())->toContain('1 = 0');
});

it('filters on the qualified tenant column of the current tenant', function () {
    $team = new Team;
    $team->id = 42;

    $query = app(TenantContext::class)->run($team, fn () => Project::query()->toBase());

    // Identifier quoting differs per database driver (SQLite locally, MySQL in CI).
    expect($query->toSql())->toMatch('/["`]projects["`]\.["`]tenant_id["`] = \?/')
        ->and($query->getBindings())->toBe([42]);
});

it('only goes away when a query explicitly drops global scopes', function () {
    expect(Project::withoutGlobalScopes()->toSql())->not->toContain('tenant_id')
        ->and(Project::query()->toSql())->toContain('1 = 0');
});
