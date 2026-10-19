<?php

namespace Tests\Support;

use App\Billing\BillingGateway;
use App\Billing\InvoiceSummary;
use App\Enums\Plan;
use App\Models\Team;
use Throwable;

/** A gateway for tests that records what the app asks of it and returns whatever the test sets up. */
class RecordingGateway implements BillingGateway
{
    /** @var list<array{0: string, 1: mixed}> */
    public array $calls = [];

    /** @var list<int> */
    public array $terminated = [];

    public ?string $portalUrl = 'https://billing.stripe.test/session/abc';

    /** @var list<InvoiceSummary> */
    public array $invoices = [];

    public ?Throwable $invoiceError = null;

    public ?string $checkoutUrl = null;

    public ?Throwable $portalError = null;

    /** Thrown by subscribe, changePlan, cancel and resume, like a payment provider that is down or refuses. */
    public ?Throwable $error = null;

    public function subscribe(Team $team, Plan $plan): ?string
    {
        $this->calls[] = ['subscribe', $plan];
        $this->failIfTold();

        return $this->checkoutUrl;
    }

    public function changePlan(Team $team, Plan $plan): void
    {
        $this->calls[] = ['changePlan', $plan];
        $this->failIfTold();
    }

    public function cancel(Team $team): void
    {
        $this->calls[] = ['cancel', null];
        $this->failIfTold();
    }

    public function resume(Team $team): void
    {
        $this->calls[] = ['resume', null];
        $this->failIfTold();
    }

    public function terminate(Team $team): void
    {
        $this->terminated[] = $team->getKey();
    }

    public function supportsPortal(): bool
    {
        return $this->portalUrl !== null;
    }

    public function portalUrl(Team $team, string $returnUrl): string
    {
        $this->calls[] = ['portal', $returnUrl];

        if ($this->portalError !== null) {
            throw $this->portalError;
        }

        return (string) $this->portalUrl;
    }

    public function invoices(Team $team): array
    {
        if ($this->invoiceError !== null) {
            throw $this->invoiceError;
        }

        return $this->invoices;
    }

    private function failIfTold(): void
    {
        if ($this->error !== null) {
            throw $this->error;
        }
    }

    /** @return list<string> the names of the calls made so far */
    public function called(): array
    {
        return array_map(fn (array $call) => $call[0], $this->calls);
    }
}
