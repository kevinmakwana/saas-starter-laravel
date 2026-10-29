<?php

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;

it('seeds a demo team the demo user can log into', function () {
    $this->seed(DatabaseSeeder::class);

    $this->post('/login', ['email' => 'demo@acme.test', 'password' => 'password'])->assertRedirect(route('dashboard', absolute: false));

    $team = Team::firstWhere('name', 'Acme Demo');
    expect($team->currentPlan()->value)->toBe('business')
        ->and($team->users()->count())->toBe(3)
        ->and(asTenant($team, fn () => Project::count()))->toBe(3);
});

it('is idempotent', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(User::count())->toBe(3)
        ->and(Team::count())->toBe(1)
        ->and(asTenant(Team::first(), fn () => Project::count()))->toBe(3);
});

it('gives the demo owner a few notifications for the header bell, once', function () {
    $this->seed();
    $this->seed();

    $owner = User::firstWhere('email', 'demo@acme.test');

    expect($owner->notifications()->count())->toBe(3)
        ->and($owner->unreadNotifications()->count())->toBe(3)
        ->and(User::firstWhere('email', 'admin@acme.test')->notifications()->count())->toBe(0);
});
