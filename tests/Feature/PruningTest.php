<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\ProcessedWebhookEvent;
use App\Models\Team;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

function invitationExpiring(Team $team, string $email, $expiresAt): void
{
    $team->invitations()->create([
        'email' => $email, 'role' => Role::Member, 'expires_at' => $expiresAt,
    ]);
}

function webhookEvent(string $id, $processedAt): void
{
    ProcessedWebhookEvent::create([
        'event_id' => $id, 'type' => 'invoice.paid', 'payload' => [], 'processed_at' => $processedAt,
    ]);
}

function auditEntry(Team $team, $createdAt): void
{
    DB::table('audit_logs')->insert([
        'tenant_id' => $team->id, 'action' => 'test.action', 'summary' => 'x', 'created_at' => $createdAt,
    ]);
}

it('is scheduled to run daily', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains($event->command, 'model:prune'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *');
});

it('prunes invitations that expired long ago and keeps recent and pending ones', function () {
    $team = ownerWithTeam()->currentTeam;
    invitationExpiring($team, 'old@example.com', now()->subDays(45));
    invitationExpiring($team, 'recent@example.com', now()->subDays(5));
    invitationExpiring($team, 'pending@example.com', now()->addDays(3));

    $this->artisan('model:prune')->assertSuccessful();

    expect($team->invitations()->pluck('email')->all())->toEqualCanonicalizing(['recent@example.com', 'pending@example.com']);
});

it('prunes old processed webhook events but never unprocessed ones', function () {
    webhookEvent('evt_old', now()->subDays(45));
    webhookEvent('evt_recent', now()->subDays(5));
    webhookEvent('evt_stuck', null);
    ProcessedWebhookEvent::where('event_id', 'evt_stuck')->update(['created_at' => now()->subDays(90)]);

    $this->artisan('model:prune')->assertSuccessful();

    expect(ProcessedWebhookEvent::pluck('event_id')->all())->toEqualCanonicalizing(['evt_recent', 'evt_stuck']);
});

it('keeps audit logs forever unless a retention period is configured', function () {
    config(['retention.audit_logs_days' => null]);
    $team = ownerWithTeam()->currentTeam;
    auditEntry($team, now()->subYears(3));

    $this->artisan('model:prune')->assertSuccessful();

    expect(DB::table('audit_logs')->count())->toBe(1);
});

it('prunes audit logs older than the configured retention across all tenants', function () {
    config(['retention.audit_logs_days' => 90]);
    $first = ownerWithTeam()->currentTeam;
    $second = ownerWithTeam()->currentTeam;
    auditEntry($first, now()->subDays(120));
    auditEntry($second, now()->subDays(120));
    auditEntry($first, now()->subDays(10));

    $this->artisan('model:prune')->assertSuccessful();

    expect(DB::table('audit_logs')->count())->toBe(1)
        ->and(asTenant($first, fn () => AuditLog::count()))->toBe(1);
});
