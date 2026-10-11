<?php

use App\Enums\Plan;
use App\Mail\PaymentFailedMail;
use App\Models\AuditLog;
use App\Models\ProcessedWebhookEvent;
use App\Services\FeatureGate;
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
