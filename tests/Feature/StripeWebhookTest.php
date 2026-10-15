<?php

use App\Billing\BillingGateway;
use App\Enums\Plan;
use App\Jobs\ProcessStripeWebhook;
use App\Mail\PaymentFailedMail;
use App\Models\AuditLog;
use App\Models\ProcessedWebhookEvent;
use App\Models\Team;
use App\Services\FeatureGate;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

function stripeEvent(string $id, string $type, array $object): array
{
    return ['id' => $id, 'object' => 'event', 'type' => $type, 'api_version' => '2024-06-20', 'data' => ['object' => $object]];
}

function postWebhook(array $event, ?string $secret = null, ?int $timestamp = null)
{
    $payload = json_encode($event);
    $timestamp ??= time();
    $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret ?? config('billing.webhook.secret'));

    return test()->call('POST', '/webhooks/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        'CONTENT_TYPE' => 'application/json',
    ], $payload);
}

function subscriptionObject(string $customer, string $price, array $extra = []): array
{
    return array_merge([
        'object' => 'subscription', 'id' => 'sub_1', 'customer' => $customer, 'status' => 'active',
        'items' => ['data' => [['price' => ['id' => $price]]]],
    ], $extra);
}

beforeEach(function () {
    config(['billing.plans.pro.stripe_price' => 'price_pro', 'billing.plans.business.stripe_price' => 'price_biz']);
    $this->team = ownerWithTeam('free')->currentTeam;
    $this->team->forceFill(['stripe_id' => 'cus_123'])->save();
});

it('rejects missing and invalid signatures', function () {
    $event = stripeEvent('evt_1', 'customer.subscription.updated', subscriptionObject('cus_123', 'price_pro'));

    $this->postJson('/webhooks/stripe', $event)->assertForbidden();
    postWebhook($event, 'whsec_wrong')->assertForbidden();
    postWebhook($event, null, time() - 3600)->assertForbidden();

    expect(ProcessedWebhookEvent::count())->toBe(0)
        ->and($this->team->refresh()->plan)->toBe('free');
});

it('rejects every webhook when no secret is configured', function () {
    config(['billing.webhook.secret' => '']);

    postWebhook(stripeEvent('evt_1', 'customer.subscription.updated', subscriptionObject('cus_123', 'price_pro')), '')->assertForbidden();

    expect(ProcessedWebhookEvent::count())->toBe(0);
});

it('applies a verified subscription update', function () {
    postWebhook(stripeEvent('evt_1', 'customer.subscription.updated', subscriptionObject('cus_123', 'price_pro')))
        ->assertOk()->assertJson(['status' => 'queued']);

    expect($this->team->refresh()->plan)->toBe('pro')
        ->and(ProcessedWebhookEvent::firstWhere('event_id', 'evt_1')->processed_at)->not->toBeNull();
});

it('is idempotent: a redelivered event id is acknowledged but not processed again', function () {
    $upgrade = stripeEvent('evt_dup', 'customer.subscription.updated', subscriptionObject('cus_123', 'price_biz'));

    postWebhook($upgrade)->assertJson(['status' => 'queued']);
    expect($this->team->refresh()->plan)->toBe('business');

    // Local change in between, then Stripe retries the very same event.
    $this->team->forceFill(['plan' => 'free'])->save();
    postWebhook($upgrade)->assertOk()->assertJson(['status' => 'duplicate']);

    expect($this->team->refresh()->plan)->toBe('free')
        ->and(ProcessedWebhookEvent::where('event_id', 'evt_dup')->count())->toBe(1);
});

it('drops the team to free when the subscription is deleted', function () {
    $this->team->forceFill(['plan' => 'pro'])->save();

    postWebhook(stripeEvent('evt_2', 'customer.subscription.deleted', subscriptionObject('cus_123', 'price_pro', ['status' => 'canceled'])));

    expect($this->team->refresh()->plan)->toBe('free');
});

it('keeps the plan until period end when cancel_at_period_end is set', function () {
    $end = now()->addDays(10)->timestamp;
    postWebhook(stripeEvent('evt_3', 'customer.subscription.updated', subscriptionObject('cus_123', 'price_pro', [
        'cancel_at_period_end' => true, 'current_period_end' => $end,
    ])));

    $this->team->refresh();
    expect($this->team->plan)->toBe('pro')->and($this->team->onGracePeriod())->toBeTrue();
});

it('ignores unknown customers and unrelated event types', function () {
    postWebhook(stripeEvent('evt_4', 'customer.subscription.updated', subscriptionObject('cus_unknown', 'price_pro')))->assertOk();
    postWebhook(stripeEvent('evt_5', 'invoice.paid', ['customer' => 'cus_123']))->assertOk();

    expect($this->team->refresh()->plan)->toBe('free')
        ->and(ProcessedWebhookEvent::whereNotNull('processed_at')->count())->toBe(2);
});

describe('payment failures', function () {
    beforeEach(function () {
        Mail::fake();
        $this->team->forceFill(['plan' => 'business'])->save();
        $this->owner = $this->team->owner;
    });

    function invoiceEvent(string $id, string $type, string $customer = 'cus_123'): array
    {
        return stripeEvent($id, $type, ['object' => 'invoice', 'customer' => $customer]);
    }

    it('starts a grace period, emails the owner and records an audit entry', function () {
        postWebhook(invoiceEvent('evt_f1', 'invoice.payment_failed'))->assertOk();

        $team = $this->team->refresh();
        expect($team->inPaymentGrace())->toBeTrue()
            ->and($team->payment_grace_ends_at->isSameDay(now()->addDays(7)))->toBeTrue()
            ->and($team->currentPlan())->toBe(Plan::Business);

        Mail::assertQueued(PaymentFailedMail::class, fn ($mail) => $mail->hasTo($this->owner->email) && $mail->queue === 'mail');
        expect(asTenant($team, fn () => AuditLog::pluck('action')->all()))->toContain('billing.payment_failed');
    });

    it('does not restart the clock or re-email on Stripe retries', function () {
        postWebhook(invoiceEvent('evt_f1', 'invoice.payment_failed'));
        $firstDeadline = $this->team->refresh()->payment_grace_ends_at;

        $this->travel(2)->days();
        postWebhook(invoiceEvent('evt_f2', 'invoice.payment_failed'))->assertOk();

        expect($this->team->refresh()->payment_grace_ends_at->equalTo($firstDeadline))->toBeTrue();
        Mail::assertQueuedCount(1);
    });

    it('drops to Free once the grace period is over and restores the plan when paid', function () {
        postWebhook(invoiceEvent('evt_f1', 'invoice.payment_failed'));

        $this->travel(8)->days();
        $team = $this->team->refresh();
        expect($team->paymentOverdue())->toBeTrue()
            ->and($team->currentPlan())->toBe(Plan::Free)
            ->and(app(FeatureGate::class)->allows($team, 'audit_log'))->toBeFalse();

        postWebhook(invoiceEvent('evt_p1', 'invoice.paid'))->assertOk();

        $team->refresh();
        expect($team->payment_failed_at)->toBeNull()
            ->and($team->currentPlan())->toBe(Plan::Business)
            ->and(app(FeatureGate::class)->allows($team, 'audit_log'))->toBeTrue();
    });

    it('clears the failure when payment succeeds inside the grace period', function () {
        postWebhook(invoiceEvent('evt_f1', 'invoice.payment_failed'));
        postWebhook(invoiceEvent('evt_p1', 'invoice.payment_succeeded'));

        expect($this->team->refresh()->inPaymentGrace())->toBeFalse();
        expect(asTenant($this->team, fn () => AuditLog::pluck('action')->all()))->toContain('billing.payment_recovered');
    });

    it('ignores payment events for unknown customers', function () {
        postWebhook(invoiceEvent('evt_f9', 'invoice.payment_failed', 'cus_unknown'))->assertOk();

        expect($this->team->refresh()->payment_failed_at)->toBeNull();
        Mail::assertNothingQueued();
    });

    it('shows a billing notice to team members during grace and when overdue', function () {
        $this->actingAs($this->owner)->get('/dashboard')->assertInertia(fn ($page) => $page->where('auth.billing', null));

        postWebhook(invoiceEvent('evt_f1', 'invoice.payment_failed'));
        $this->actingAs($this->owner->fresh())->get('/dashboard')->assertInertia(fn ($page) => $page->where('auth.billing.state', 'grace'));

        $this->travel(8)->days();
        $this->actingAs($this->owner->fresh())->get('/dashboard')->assertInertia(fn ($page) => $page->where('auth.billing.state', 'overdue'));
    });

    it('lets a cancelled subscription clear any outstanding failure', function () {
        postWebhook(invoiceEvent('evt_f1', 'invoice.payment_failed'));
        postWebhook(stripeEvent('evt_d1', 'customer.subscription.deleted', subscriptionObject('cus_123', 'price_pro', ['status' => 'canceled'])));

        $team = $this->team->refresh();
        expect($team->plan)->toBe('free')->and($team->payment_failed_at)->toBeNull();
    });
});

describe('recovering events that were stored but never processed', function () {
    /** An event row left behind when dispatching or processing failed. */
    function strandedEvent(string $id, array $object, $createdAt = null): ProcessedWebhookEvent
    {
        $record = ProcessedWebhookEvent::create([
            'event_id' => $id, 'type' => 'customer.subscription.updated',
            'payload' => stripeEvent($id, 'customer.subscription.updated', $object),
        ]);
        $record->forceFill(['created_at' => $createdAt ?? now()->subHour()])->save();

        return $record;
    }

    it('re-runs an unprocessed event when Stripe redelivers it', function () {
        $object = subscriptionObject('cus_123', 'price_pro');
        strandedEvent('evt_stuck', $object);

        postWebhook(stripeEvent('evt_stuck', 'customer.subscription.updated', $object))
            ->assertOk()->assertJson(['status' => 'queued']);

        expect($this->team->refresh()->plan)->toBe('pro')
            ->and(ProcessedWebhookEvent::firstWhere('event_id', 'evt_stuck')->processed_at)->not->toBeNull();
    });

    it('retries stranded events that are old enough, and leaves fresh, ancient and processed ones alone', function () {
        $old = strandedEvent('evt_old', subscriptionObject('cus_123', 'price_pro'), now()->subMinutes(30));
        $fresh = strandedEvent('evt_fresh', subscriptionObject('cus_123', 'price_biz'), now()->subMinute());
        $ancient = strandedEvent('evt_ancient', subscriptionObject('cus_123', 'price_biz'), now()->subDays(10));

        $this->artisan('webhooks:retry-stuck')->assertSuccessful();

        expect($old->refresh()->processed_at)->not->toBeNull()
            ->and($fresh->refresh()->processed_at)->toBeNull()
            ->and($ancient->refresh()->processed_at)->toBeNull()
            ->and($this->team->refresh()->plan)->toBe('pro');
    });

    it('replays one event by id, even if it was processed before', function () {
        postWebhook(stripeEvent('evt_replay', 'customer.subscription.updated', subscriptionObject('cus_123', 'price_pro')));
        $this->team->forceFill(['plan' => 'free'])->save();

        $this->artisan('webhooks:replay', ['event_id' => 'evt_replay'])->assertSuccessful();

        expect($this->team->refresh()->plan)->toBe('pro');
    });

    it('fails to replay an unknown event id', function () {
        $this->artisan('webhooks:replay', ['event_id' => 'evt_nope'])->assertFailed();
    });

    it('schedules the retry sweeper every five minutes', function () {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'webhooks:retry-stuck'));

        expect($event)->not->toBeNull()->and($event->expression)->toBe('*/5 * * * *');
    });

    it('logs the event id when processing fails for good', function () {
        Log::spy();

        (new ProcessStripeWebhook(42))->failed(new RuntimeException('boom'));

        Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => str_contains($message, 'Stripe webhook') && $context['record_id'] === 42);
    });

    it('does not queue the same event twice while one is already waiting', function () {
        config(['queue.default' => 'database']);

        ProcessStripeWebhook::dispatch(7);
        ProcessStripeWebhook::dispatch(7);

        expect(DB::table('jobs')->count())->toBe(1);
    });
});

describe('subscription audit entries', function () {
    it('records the plan once the subscription is confirmed by Stripe', function () {
        postWebhook(stripeEvent('evt_c1', 'customer.subscription.created', subscriptionObject('cus_123', 'price_pro')));
        postWebhook(stripeEvent('evt_c2', 'customer.subscription.updated', subscriptionObject('cus_123', 'price_pro')));

        $entries = asTenant($this->team, fn () => AuditLog::where('action', 'billing.subscribed')->pluck('summary')->all());
        expect($entries)->toBe(['Started the Pro plan']);
    });

    it('does not log a started plan while the owner is still at Stripe Checkout', function () {
        $this->app->instance(BillingGateway::class, new class implements BillingGateway
        {
            public function subscribe(Team $team, Plan $plan): ?string
            {
                return 'https://checkout.stripe.test/session';
            }

            public function changePlan(Team $team, Plan $plan): void {}

            public function cancel(Team $team): void {}

            public function resume(Team $team): void {}

            public function terminate(Team $team): void {}
        });

        $this->actingAs($this->team->owner)->post('/billing/subscribe', ['plan' => 'pro'])->assertRedirect('https://checkout.stripe.test/session');

        expect(asTenant($this->team, fn () => AuditLog::where('action', 'billing.subscribed')->count()))->toBe(0);
    });
});
