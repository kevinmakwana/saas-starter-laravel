<?php

namespace App\Http\Controllers;

use App\Billing\AlreadySubscribed;
use App\Billing\BillingGateway;
use App\Billing\InvoiceSummary;
use App\Enums\BillingInterval;
use App\Enums\Plan;
use App\Models\Team;
use App\Services\AuditLogger;
use App\Services\FeatureGate;
use App\Services\PlanLimits;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class BillingController extends Controller
{
    public function __construct(private BillingGateway $gateway, private AuditLogger $audit) {}

    public function show(Request $request, PlanLimits $limits, FeatureGate $features): Response
    {
        $team = $this->team($request);
        Gate::authorize('view', $team);
        $current = $team->currentPlan();
        $canManage = Gate::allows('manageBilling', $team);

        return Inertia::render('Billing/Show', [
            'current' => $current->value,
            'interval' => $team->billingInterval()->value,
            'trialEndsAt' => $team->trialEndsAt()?->toIso8601String(),
            // Only a team on Free can start a trial, and only if it has not used one.
            'trialDays' => $canManage && $current === Plan::Free ? $this->gateway->trialDays($team) : 0,
            'cancelsAt' => $team->onGracePeriod() ? $team->plan_ends_at?->toIso8601String() : null,
            'canManage' => $canManage,
            'portal' => $canManage && $team->hasStripeId() && $this->gateway->supportsPortal(),
            // Loaded after the page so a slow Stripe call never delays it; only the owner sees amounts.
            'invoices' => $canManage ? Inertia::defer(fn () => $this->loadInvoices($team)) : [],
            'usage' => ['projects' => $limits->usage($team, 'projects'), 'members' => $limits->usage($team, 'members')],
            'features' => $features->all($team),
            'plans' => collect(Plan::cases())->map(fn (Plan $plan) => [
                'key' => $plan->value,
                'name' => $plan->label(),
                'price' => $plan->price(),
                'yearlyPrice' => $this->gateway->offersYearly($plan) ? $plan->price(BillingInterval::Yearly) : null,
                'limits' => ['projects' => $plan->limit('projects'), 'members' => $plan->limit('members')],
                'features' => array_keys(array_filter(
                    config('billing.features'),
                    fn (array $plans) => in_array($plan->value, $plans, true),
                )),
            ])->values(),
        ]);
    }

    public function subscribe(Request $request): SymfonyResponse
    {
        $team = $this->team($request);
        Gate::authorize('manageBilling', $team);
        $plan = $this->paidPlan($request);
        $interval = $this->interval($request, fallback: BillingInterval::Monthly);

        if ($unavailable = $this->unavailable($plan, $interval)) {
            return $unavailable;
        }

        if ($team->currentPlan() !== Plan::Free) {
            return back()->with('error', 'Your team already has a paid plan. Use Switch plan or Resume instead.');
        }

        $url = null;
        if ($failed = $this->attempt(function () use ($team, $plan, $interval, &$url) {
            $url = $this->gateway->subscribe($team, $plan, $interval);
        })) {
            return $failed;
        }

        // With a redirect (Stripe Checkout) the subscription is only recorded once the webhook confirms it.
        if ($url === null) {
            $this->audit->record('billing.subscribed', "Started the {$plan->label()} plan");
        }

        return $url ? Inertia::location($url) : back()->with('success', "You're now on the {$plan->label()} plan.");
    }

    public function change(Request $request): RedirectResponse
    {
        $team = $this->team($request);
        Gate::authorize('manageBilling', $team);
        // Going back to Free is cancelling, which keeps the paid plan until the period ends.
        $plan = $this->paidPlan($request);
        // Without an explicit choice the plan changes and the billing interval stays as it is.
        $interval = $this->interval($request, fallback: $team->billingInterval());

        if ($plan === $team->currentPlan() && $interval === $team->billingInterval()) {
            return back()->with('error', "Your team is already on the {$plan->label()} plan.");
        }

        if ($unavailable = $this->unavailable($plan, $interval)) {
            return $unavailable;
        }

        if ($failed = $this->attempt(fn () => $this->gateway->changePlan($team, $plan, $interval))) {
            return $failed;
        }

        $this->audit->record('billing.changed', "Changed plan to {$plan->label()} (billed {$interval->value})");

        return back()->with('success', "Plan changed to {$plan->label()}.");
    }

    public function cancel(Request $request): RedirectResponse
    {
        $team = $this->team($request);
        Gate::authorize('manageBilling', $team);

        if ($failed = $this->attempt(fn () => $this->gateway->cancel($team))) {
            return $failed;
        }

        $this->audit->record('billing.cancelled', 'Cancelled the subscription');

        return back()->with('success', 'Subscription cancelled. You keep your plan until the end of the period.');
    }

    public function resume(Request $request): RedirectResponse
    {
        $team = $this->team($request);
        Gate::authorize('manageBilling', $team);

        if ($failed = $this->attempt(fn () => $this->gateway->resume($team))) {
            return $failed;
        }

        $this->audit->record('billing.resumed', 'Resumed the subscription');

        return back()->with('success', 'Subscription resumed.');
    }

    /** Sends the owner to Stripe's Customer Portal (payment method, invoices). The payment failed email links here. */
    public function portal(Request $request): RedirectResponse
    {
        // The email names its team, which may not be the one the owner currently has open.
        $team = $request->query('team') === null
            ? $this->team($request)
            : $this->user($request)->teams()->whereKey((int) $request->query('team'))->firstOrFail();
        Gate::authorize('manageBilling', $team);

        if (! $team->hasStripeId() || ! $this->gateway->supportsPortal()) {
            return redirect()->route('billing.show')
                ->with('error', 'The billing portal is available once you have a paid subscription.');
        }

        try {
            return redirect()->away($this->gateway->portalUrl($team, route('billing.show')));
        } catch (Throwable $e) {
            // Typically Stripe's portal has no saved configuration yet (see the README). Tell the owner, tell us.
            report($e);

            return redirect()->route('billing.show')
                ->with('error', 'We could not open the billing portal right now. Please try again later or contact support.');
        }
    }

    /** Runs a call to the payment provider. A failure becomes a message for the owner, not a server error. */
    private function attempt(Closure $action): ?RedirectResponse
    {
        try {
            $action();

            return null;
        } catch (AlreadySubscribed $e) {
            return back()->with('error', $e->getMessage());
        } catch (IncompletePayment) {
            return back()->with('error', 'Your bank needs you to confirm this payment. Update your payment method with the billing portal, then try again.');
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'We could not reach our payment provider, so nothing was changed. Please try again in a moment.');
        }
    }

    /**
     * @return list<array<string, mixed>>|null null when the provider could not be reached
     */
    private function loadInvoices(Team $team): ?array
    {
        try {
            return array_map(fn (InvoiceSummary $invoice) => $invoice->toArray(), $this->gateway->invoices($team));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    private function interval(Request $request, BillingInterval $fallback): BillingInterval
    {
        $interval = $request->validate(['interval' => ['sometimes', Rule::enum(BillingInterval::class)]])['interval'] ?? null;

        return $interval === null ? $fallback : BillingInterval::from($interval);
    }

    /** Yearly billing needs a price from the gateway; a crafted request must not reach it without one. */
    private function unavailable(Plan $plan, BillingInterval $interval): ?RedirectResponse
    {
        if ($interval === BillingInterval::Yearly && ! $this->gateway->offersYearly($plan)) {
            return back()->with('error', "Yearly billing is not available for the {$plan->label()} plan.");
        }

        return null;
    }

    private function paidPlan(Request $request): Plan
    {
        $plan = Plan::from($request->validate([
            'plan' => ['required', Rule::enum(Plan::class)->except([Plan::Free])],
        ])['plan']);

        return $plan;
    }
}
