<?php

use App\Models\ProcessedWebhookEvent;

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
