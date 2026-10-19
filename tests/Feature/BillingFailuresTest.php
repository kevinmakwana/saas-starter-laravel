<?php

use App\Billing\AlreadySubscribed;
use App\Billing\BillingGateway;
use App\Billing\StripeGateway;
use App\Enums\Plan;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Laravel\Cashier\Payment;
use Stripe\PaymentIntent;
use Tests\Support\RecordingGateway;

beforeEach(function () {
    $this->gateway = new RecordingGateway;
    $this->app->instance(BillingGateway::class, $this->gateway);

    $this->owner = ownerWithTeam('pro');
    $this->team = $this->owner->currentTeam;
});

function billingAudit($team): array
{
    return asTenant($team, fn () => AuditLog::where('action', 'like', 'billing.%')->pluck('action')->all());
}

describe('when the payment provider fails', function () {
    it('shows an error and records nothing, whichever billing action it was', function (string $method, string $uri, array $data) {
        $this->team->forceFill(['plan' => 'free'])->save();
        $this->gateway->error = new RuntimeException('Stripe is down');

        $this->actingAs($this->owner)->from('/billing')->{$method}($uri, $data)
            ->assertRedirect('/billing')
            ->assertSessionHas('error');

        expect(billingAudit($this->team))->toBe([]);
    })->with([
        'subscribe' => ['post', '/billing/subscribe', ['plan' => 'pro']],
        'change' => ['post', '/billing/change', ['plan' => 'business']],
        'cancel' => ['post', '/billing/cancel', []],
        'resume' => ['post', '/billing/resume', []],
    ]);

    it('asks for the bank confirmation instead of a crash when a plan switch needs one', function () {
        $this->gateway->error = IncompletePayment::requiresAction(new Payment(PaymentIntent::constructFrom(['id' => 'pi_1'])));

        $this->actingAs($this->owner)->from('/billing')->post('/billing/change', ['plan' => 'business'])
            ->assertRedirect('/billing')
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'confirm'));
    });

    it('does not touch the plan the team has when the switch failed', function () {
        $this->gateway->error = new RuntimeException('declined');

        $this->actingAs($this->owner)->post('/billing/change', ['plan' => 'business']);

        expect($this->team->refresh()->currentPlan())->toBe(Plan::Pro);
    });
});

describe('plan changes', function () {
    it('sends downgrades to the cancel button, since Free is not a Stripe subscription', function () {
        $this->actingAs($this->owner)->post('/billing/change', ['plan' => 'free'])->assertSessionHasErrors('plan');

        expect($this->gateway->calls)->toBe([]);
    });

    it('says so instead of logging a change when the team is already on that plan', function () {
        $this->actingAs($this->owner)->from('/billing')->post('/billing/change', ['plan' => 'pro'])
            ->assertSessionHas('error');

        expect($this->gateway->calls)->toBe([])->and(billingAudit($this->team))->toBe([]);
    });
});

describe('subscribing twice', function () {
    it('is refused for a team that already has a paid plan', function () {
        $this->actingAs($this->owner)->from('/billing')->post('/billing/subscribe', ['plan' => 'business'])
            ->assertSessionHas('error');

        expect($this->gateway->calls)->toBe([]);
    });

    it('is refused by the Stripe gateway while a subscription exists, even if a payment problem made the plan Free', function (string $status) {
        DB::table('subscriptions')->insert([
            'team_id' => $this->team->id, 'type' => 'default', 'stripe_id' => 'sub_1', 'stripe_status' => $status,
        ]);
        config(['billing.plans.pro.stripe_price' => 'price_pro']);

        expect(fn () => (new StripeGateway)->subscribe($this->team, Plan::Pro))->toThrow(AlreadySubscribed::class);
    })->with(['active', 'past_due', 'trialing']);

    it('allows it again once the old subscription has ended', function () {
        DB::table('subscriptions')->insert([
            'team_id' => $this->team->id, 'type' => 'default', 'stripe_id' => 'sub_old', 'stripe_status' => 'canceled', 'ends_at' => now()->subDay(),
        ]);

        $existing = $this->team->subscription('default');
        expect($existing->ended())->toBeTrue();
        // Past the guard the gateway talks to Stripe, which tests never do: any other failure than AlreadySubscribed proves the guard let it through.
        expect(fn () => (new StripeGateway)->subscribe($this->team, Plan::Pro))->not->toThrow(AlreadySubscribed::class);
    });
});
