<?php

use App\Models\Team;
use App\Services\FeatureGate;

/** @param array<string, mixed> $attributes */
function teamOn(string $plan, array $attributes = []): Team
{
    $team = new Team;
    $team->plan = $plan;
    foreach ($attributes as $key => $value) {
        $team->{$key} = $value;
    }

    return $team;
}

it('allows a feature only on the plans that include it', function () {
    $gate = new FeatureGate;

    expect($gate->allows(teamOn('business'), 'audit_log'))->toBeTrue()
        ->and($gate->allows(teamOn('pro'), 'audit_log'))->toBeFalse()
        ->and($gate->allows(teamOn('pro'), 'task_export'))->toBeTrue()
        ->and($gate->allows(teamOn('free'), 'task_export'))->toBeFalse();
});

it('denies unknown features', function () {
    expect((new FeatureGate)->allows(teamOn('business'), 'time_travel'))->toBeFalse();
});

it('lists every configured feature for the team', function () {
    expect((new FeatureGate)->all(teamOn('pro')))->toBe(['audit_log' => false, 'task_export' => true])
        ->and((new FeatureGate)->all(teamOn('business')))->toBe(['audit_log' => true, 'task_export' => true])
        ->and((new FeatureGate)->all(teamOn('free')))->toBe(['audit_log' => false, 'task_export' => false]);
});

it('uses the plan in force, not the plan name stored on the team', function () {
    $ended = teamOn('business', ['plan_ends_at' => now()->subDay()]);
    $overdue = teamOn('business', ['payment_failed_at' => now()->subDays(10), 'payment_grace_ends_at' => now()->subDay()]);
    $inGrace = teamOn('business', ['payment_failed_at' => now()->subDay(), 'payment_grace_ends_at' => now()->addDays(6)]);
    $cancelledButPaid = teamOn('business', ['plan_ends_at' => now()->addDays(5)]);

    $gate = new FeatureGate;

    expect($gate->allows($ended, 'audit_log'))->toBeFalse()
        ->and($gate->allows($overdue, 'audit_log'))->toBeFalse()
        ->and($gate->allows($inGrace, 'audit_log'))->toBeTrue()
        ->and($gate->allows($cancelledButPaid, 'audit_log'))->toBeTrue();
});

it('falls back to Free for an unknown stored plan', function () {
    expect((new FeatureGate)->allows(teamOn('platinum'), 'task_export'))->toBeFalse();
});
