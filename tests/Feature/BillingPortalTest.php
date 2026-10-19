<?php

use App\Billing\BillingGateway;
use App\Billing\InvoiceSummary;
use App\Enums\Role;
use App\Mail\PaymentFailedMail;
use App\Models\Team;
use App\Models\User;
use Tests\Support\RecordingGateway;

beforeEach(function () {
    $this->gateway = new RecordingGateway;
    $this->app->instance(BillingGateway::class, $this->gateway);

    $this->owner = ownerWithTeam('pro');
    $this->team = $this->owner->currentTeam;
    $this->team->forceFill(['stripe_id' => 'cus_123'])->save();
});

function invoice(string $number, string $total = '$29.00'): InvoiceSummary
{
    return new InvoiceSummary(
        id: 'in_'.$number, number: $number, date: '2026-10-01T00:00:00+00:00', total: $total,
        status: 'paid', hostedUrl: "https://invoice.stripe.test/{$number}", pdfUrl: "https://invoice.stripe.test/{$number}.pdf",
    );
}

describe('customer portal', function () {
    it('sends the owner to the Stripe portal and back to the billing page afterwards', function () {
        $this->actingAs($this->owner)->get('/billing/portal')->assertRedirect('https://billing.stripe.test/session/abc');

        expect($this->gateway->calls)->toBe([['portal', route('billing.show')]]);
    });

    it('opens the portal of the team named in the link, even when another team is current', function () {
        $second = Team::createFor($this->owner, 'Second team');
        expect($this->owner->fresh()->current_team_id)->toBe($second->id);

        $this->actingAs($this->owner->fresh())->get('/billing/portal?team='.$this->team->id)->assertRedirect('https://billing.stripe.test/session/abc');

        expect($this->gateway->calls)->toHaveCount(1);
    });

    it('does not open the portal of a team the person does not belong to', function () {
        $stranger = Team::factory()->create(['stripe_id' => 'cus_other']);

        $this->actingAs($this->owner)->get('/billing/portal?team='.$stranger->id)->assertNotFound();

        expect($this->gateway->calls)->toBe([]);
    });

    it('is for the owner only, also when the team comes from the link', function () {
        $admin = User::factory()->create();
        $this->team->addMember($admin, Role::Admin);
        $admin->switchTeam(Team::createFor($admin, 'Mine'));

        $this->actingAs($admin->fresh())->get('/billing/portal?team='.$this->team->id)->assertForbidden();
    });

    it('is for the owner only', function () {
        $admin = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->team->addMember($admin, Role::Admin);

        $this->actingAs($admin)->get('/billing/portal')->assertForbidden();
        expect($this->gateway->calls)->toBe([]);
    });

    it('needs a Stripe customer, which only exists after the first subscription', function () {
        $this->team->forceFill(['stripe_id' => null])->save();

        $this->actingAs($this->owner)->get('/billing/portal')
            ->assertRedirect(route('billing.show'))->assertSessionHas('error');

        expect($this->gateway->calls)->toBe([]);
    });

    it('explains itself when the gateway has no portal, like the fake one', function () {
        $this->gateway->portalUrl = null;

        $this->actingAs($this->owner)->get('/billing/portal')
            ->assertRedirect(route('billing.show'))->assertSessionHas('error');
    });

    it('shows an error instead of a crash when Stripe cannot open the portal, e.g. it is not configured yet', function () {
        $this->gateway->portalError = new RuntimeException('No configuration provided');

        $this->actingAs($this->owner)->get('/billing/portal')
            ->assertRedirect(route('billing.show'))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'billing portal'));
    });

    it('tells the billing page whether the portal is available', function (bool $isOwner, ?string $stripeId, ?string $portal, bool $expected) {
        $this->team->forceFill(['stripe_id' => $stripeId])->save();
        $this->gateway->portalUrl = $portal;
        $viewer = $this->owner;
        if (! $isOwner) {
            $viewer = User::factory()->create(['current_team_id' => $this->team->id]);
            $this->team->addMember($viewer, Role::Member);
        }

        $this->actingAs($viewer)->get('/billing')->assertInertia(fn ($page) => $page->where('portal', $expected));
    })->with([
        'owner with a customer' => [true, 'cus_1', 'https://p.test', true],
        'owner without a customer' => [true, null, 'https://p.test', false],
        'owner on a gateway without portal' => [true, 'cus_1', null, false],
        'member' => [false, 'cus_1', 'https://p.test', false],
    ]);

    it('links the payment failed email straight to the portal route', function () {
        $mail = new PaymentFailedMail($this->team->forceFill(['payment_grace_ends_at' => now()->addDays(7)]));

        expect($mail->render())->toContain(route('billing.portal', ['team' => $this->team->id]))->toContain('Update payment method');
    });
});

describe('invoice history', function () {
    it('loads the invoices after the page, for the owner', function () {
        $this->gateway->invoices = [invoice('A-0002', '$29.00'), invoice('A-0001', '$29.00')];

        $this->actingAs($this->owner)->get('/billing')->assertInertia(fn ($page) => $page
            ->missing('invoices')
            ->loadDeferredProps(fn ($reloaded) => $reloaded
                ->has('invoices', 2)
                ->where('invoices.0.number', 'A-0002')
                ->where('invoices.0.total', '$29.00')
                ->where('invoices.0.status', 'paid')
                ->where('invoices.0.hosted_url', 'https://invoice.stripe.test/A-0002')
                ->where('invoices.0.pdf_url', 'https://invoice.stripe.test/A-0002.pdf')));
    });

    it('is an empty list when there are no invoices yet', function () {
        $this->actingAs($this->owner)->get('/billing')->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reloaded) => $reloaded->where('invoices', [])));
    });

    it('does not break the billing page when Stripe cannot be reached', function () {
        $this->gateway->invoiceError = new RuntimeException('Stripe is down');

        $this->actingAs($this->owner)->get('/billing')->assertOk()->assertInertia(fn ($page) => $page
            ->loadDeferredProps(fn ($reloaded) => $reloaded->where('invoices', null)));
    });

    it('is never loaded for people who cannot manage billing', function () {
        $this->gateway->invoices = [invoice('A-0001')];
        $member = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->team->addMember($member, Role::Member);

        $this->actingAs($member)->get('/billing')->assertInertia(fn ($page) => $page->where('invoices', []));
    });
});
