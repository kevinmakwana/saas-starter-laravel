<?php

use App\Billing\BillingGateway;
use App\Enums\Plan;
use App\Jobs\ProcessStripeWebhook;
use App\Mail\PaymentFailedMail;
use App\Mail\TrialEndingMail;
use App\Models\AuditLog;
use App\Models\ProcessedWebhookEvent;
use App\Services\FeatureGate;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\Support\RecordingGateway;

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
        'items' => ['data' => [['id' => 'si_1', 'quantity' => 1, 'price' => ['id' => $price, 'product' => 'prod_1']]]],
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
        $note = $this->owner->notifications()->where('data->kind', 'payment.failed')->first();
        expect($note->data)->toMatchArray(['team_id' => $team->id, 'url' => '/billing', 'params' => ['team' => $team->name]]);
    });

    it('does not restart the clock or re-email on Stripe retries', function () {
        postWebhook(invoiceEvent('evt_f1', 'invoice.payment_failed'));
        $firstDeadline = $this->team->refresh()->payment_grace_ends_at;

        $this->travel(2)->days();
        postWebhook(invoiceEvent('evt_f2', 'invoice.payment_failed'))->assertOk();

        expect($this->team->refresh()->payment_grace_ends_at->equalTo($firstDeadline))->toBeTrue()
            ->and($this->owner->notifications()->where('data->kind', 'payment.failed')->count())->toBe(1);
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
        $gateway = new RecordingGateway;
        $gateway->checkoutUrl = 'https://checkout.stripe.test/session';
        $this->app->instance(BillingGateway::class, $gateway);

        $this->actingAs($this->team->owner)->post('/billing/subscribe', ['plan' => 'pro'])->assertRedirect('https://checkout.stripe.test/session');

        expect(asTenant($this->team, fn () => AuditLog::where('action', 'billing.subscribed')->count()))->toBe(0);
    });
});

describe('Cashier subscription records', function () {
    it('keeps the subscriptions table in step with Stripe, which changePlan, cancel and resume rely on', function () {
        postWebhook(stripeEvent('evt_s1', 'customer.subscription.created', subscriptionObject('cus_123', 'price_pro')));

        $subscription = $this->team->subscription('default');
        expect($subscription)->not->toBeNull()
            ->and($subscription->stripe_id)->toBe('sub_1')
            ->and($subscription->stripe_price)->toBe('price_pro')
            ->and($subscription->valid())->toBeTrue();

        postWebhook(stripeEvent('evt_s2', 'customer.subscription.updated', subscriptionObject('cus_123', 'price_biz', [
            'cancel_at_period_end' => true, 'cancel_at' => now()->addDays(5)->timestamp,
        ])));

        $subscription->refresh();
        expect($subscription->stripe_price)->toBe('price_biz')
            ->and($subscription->onGracePeriod())->toBeTrue();

        postWebhook(stripeEvent('evt_s3', 'customer.subscription.deleted', subscriptionObject('cus_123', 'price_biz', ['status' => 'canceled'])));

        expect($subscription->refresh()->ended())->toBeTrue()
            ->and($this->team->refresh()->plan)->toBe('free');
    });

    it('has no second, Cashier-owned webhook endpoint that could be called without the app signature check', function () {
        $this->postJson('/stripe/webhook', stripeEvent('evt_x', 'customer.subscription.deleted', subscriptionObject('cus_123', 'price_pro')))
            ->assertNotFound();
    });
});

describe('unusual subscription events', function () {
    it('finds the end of a cancelled period however the Stripe API version reports it', function (array $extra) {
        postWebhook(stripeEvent('evt_e1', 'customer.subscription.updated', subscriptionObject('cus_123', 'price_pro', ['cancel_at_period_end' => true, ...$extra])));

        expect($this->team->refresh()->plan_ends_at?->timestamp)->toBe(1893456000)
            ->and($this->team->plan)->toBe('pro');
    })->with([
        'cancel_at' => [['cancel_at' => 1893456000]],
        'on the subscription' => [['current_period_end' => 1893456000]],
        'on the item (newer API versions)' => [['items' => ['data' => [['id' => 'si_1', 'price' => ['id' => 'price_pro', 'product' => 'prod_1'], 'current_period_end' => 1893456000]]]]],
    ]);

    it('does not grant the plan while the first payment is still incomplete', function () {
        postWebhook(stripeEvent('evt_i1', 'customer.subscription.created', subscriptionObject('cus_123', 'price_pro', ['status' => 'incomplete'])));

        expect($this->team->refresh()->plan)->toBe('free')
            ->and(asTenant($this->team, fn () => AuditLog::where('action', 'billing.subscribed')->count()))->toBe(0);

        // Once the payment goes through Stripe says so with an update, and that is when the plan starts.
        postWebhook(stripeEvent('evt_i2', 'customer.subscription.updated', subscriptionObject('cus_123', 'price_pro')) + []);

        expect($this->team->refresh()->plan)->toBe('pro');
    });

    it('records the start of a plan that only became active after an incomplete payment', function () {
        postWebhook(stripeEvent('evt_i1', 'customer.subscription.created', subscriptionObject('cus_123', 'price_pro', ['status' => 'incomplete'])));

        $update = stripeEvent('evt_i2', 'customer.subscription.updated', subscriptionObject('cus_123', 'price_pro'));
        $update['data']['previous_attributes'] = ['status' => 'incomplete'];
        postWebhook($update);

        expect(asTenant($this->team, fn () => AuditLog::where('action', 'billing.subscribed')->pluck('summary')->all()))->toBe(['Started the Pro plan']);
    });

    it('ignores an older event that arrives after a newer one', function () {
        $deleted = stripeEvent('evt_new', 'customer.subscription.deleted', subscriptionObject('cus_123', 'price_pro', ['status' => 'canceled']));
        $deleted['created'] = 2000;
        $staleUpdate = stripeEvent('evt_old', 'customer.subscription.updated', subscriptionObject('cus_123', 'price_pro'));
        $staleUpdate['created'] = 1000;

        $this->team->forceFill(['plan' => 'pro'])->save();
        postWebhook($deleted);
        postWebhook($staleUpdate)->assertOk();

        expect($this->team->refresh()->plan)->toBe('free')
            ->and(ProcessedWebhookEvent::firstWhere('event_id', 'evt_old')->processed_at)->not->toBeNull();
    });

    it('still applies events that carry the same timestamp or no timestamp', function () {
        $first = stripeEvent('evt_a', 'customer.subscription.updated', subscriptionObject('cus_123', 'price_pro'));
        $second = stripeEvent('evt_b', 'customer.subscription.updated', subscriptionObject('cus_123', 'price_biz'));
        $first['created'] = $second['created'] = 5000;

        postWebhook($first);
        postWebhook($second);

        expect($this->team->refresh()->plan)->toBe('business');
    });

    it('logs a subscription for a price it does not know instead of silently ignoring it', function () {
        Log::spy();

        postWebhook(stripeEvent('evt_u1', 'customer.subscription.updated', subscriptionObject('cus_123', 'price_mystery')))->assertOk();

        expect($this->team->refresh()->plan)->toBe('free');
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => str_contains($message, 'unknown price') && $context['price'] === 'price_mystery');
    });

    it('finishes quietly when its stored event was pruned in the meantime', function () {
        (new ProcessStripeWebhook(987654))->handle();

        expect(ProcessedWebhookEvent::count())->toBe(0);
    });
});

describe('trial ending', function () {
    beforeEach(function () {
        Mail::fake();
        $this->owner = $this->team->owner;
    });

    function trialEndingEvent(string $id, string $customer = 'cus_123', array $extra = []): array
    {
        return stripeEvent($id, 'customer.subscription.trial_will_end', subscriptionObject($customer, 'price_pro', array_merge([
            'status' => 'trialing', 'trial_end' => now()->addDays(3)->timestamp,
        ], $extra)));
    }

    it('emails the owner before the trial turns into a paid plan', function () {
        postWebhook(trialEndingEvent('evt_t1'))->assertOk();

        Mail::assertQueued(TrialEndingMail::class, fn ($mail) => $mail->hasTo($this->owner->email)
            && $mail->queue === 'mail'
            && $mail->endsAt->isSameDay(now()->addDays(3)));
        expect(ProcessedWebhookEvent::firstWhere('event_id', 'evt_t1')->processed_at)->not->toBeNull();
        $note = $this->owner->notifications()->where('data->kind', 'trial.ending')->first();
        expect($note->data)->toMatchArray(['team_id' => $this->team->id, 'url' => '/billing'])
            ->and($note->data['params']['team'])->toBe($this->team->name)
            ->and($note->data['params']['date'])->toBe(now()->addDays(3)->toDateString());
    });

    it('stays quiet when the subscription is no longer trialing', function () {
        postWebhook(trialEndingEvent('evt_t2', extra: ['status' => 'canceled']))->assertOk();
        postWebhook(trialEndingEvent('evt_t3', extra: ['status' => 'active']))->assertOk();

        Mail::assertNothingQueued();
        expect($this->owner->notifications()->count())->toBe(0);
    });

    it('stays quiet for an unknown customer or a trial that will not convert', function () {
        postWebhook(trialEndingEvent('evt_t4', 'cus_unknown'))->assertOk();
        postWebhook(trialEndingEvent('evt_t5', extra: ['cancel_at_period_end' => true]))->assertOk();

        Mail::assertNothingQueued();
        expect($this->owner->notifications()->count())->toBe(0);
    });

    it('does not email twice when Stripe redelivers the event', function () {
        postWebhook(trialEndingEvent('evt_t6'));
        postWebhook(trialEndingEvent('evt_t6'))->assertJson(['status' => 'duplicate']);

        Mail::assertQueuedCount(1);
        expect($this->owner->notifications()->count())->toBe(1);
    });

    it('keeps the paid plan while the subscription is trialing', function () {
        postWebhook(stripeEvent('evt_t7', 'customer.subscription.created', subscriptionObject('cus_123', 'price_pro', ['status' => 'trialing'])));

        expect($this->team->refresh()->currentPlan())->toBe(Plan::Pro);
    });

    it('recognises the yearly price as the same plan', function () {
        config(['billing.plans.pro.stripe_price_yearly' => 'price_pro_year']);

        postWebhook(stripeEvent('evt_t8', 'customer.subscription.updated', subscriptionObject('cus_123', 'price_pro_year')));

        expect($this->team->refresh()->currentPlan())->toBe(Plan::Pro);
    });
});
