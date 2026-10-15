<?php

namespace App\Http\Controllers;

use App\Billing\BillingGateway;
use App\Enums\Plan;
use App\Services\AuditLogger;
use App\Services\FeatureGate;
use App\Services\PlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class BillingController extends Controller
{
    public function __construct(private BillingGateway $gateway, private AuditLogger $audit) {}

    public function show(Request $request, PlanLimits $limits, FeatureGate $features): Response
    {
        $team = $request->user()->currentTeam;
        Gate::authorize('view', $team);
        $current = $team->currentPlan();

        return Inertia::render('Billing/Show', [
            'current' => $current->value,
            'cancelsAt' => $team->onGracePeriod() ? $team->plan_ends_at?->toIso8601String() : null,
            'canManage' => Gate::allows('manageBilling', $team),
            'usage' => ['projects' => $limits->usage($team, 'projects'), 'members' => $limits->usage($team, 'members')],
            'features' => $features->all($team),
            'plans' => collect(Plan::cases())->map(fn (Plan $plan) => [
                'key' => $plan->value,
                'name' => $plan->label(),
                'price' => $plan->price(),
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
        $team = $request->user()->currentTeam;
        Gate::authorize('manageBilling', $team);
        $plan = $this->paidPlan($request);

        $url = $this->gateway->subscribe($team, $plan);

        // With a redirect (Stripe Checkout) the subscription is only recorded once the webhook confirms it.
        if ($url === null) {
            $this->audit->record('billing.subscribed', "Started the {$plan->label()} plan");
        }

        return $url ? Inertia::location($url) : back()->with('success', "You're now on the {$plan->label()} plan.");
    }

    public function change(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        Gate::authorize('manageBilling', $team);
        $plan = Plan::from($request->validate(['plan' => ['required', Rule::enum(Plan::class)]])['plan']);

        $this->gateway->changePlan($team, $plan);

        $this->audit->record('billing.changed', "Changed plan to {$plan->label()}");

        return back()->with('success', "Plan changed to {$plan->label()}.");
    }

    public function cancel(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        Gate::authorize('manageBilling', $team);

        $this->gateway->cancel($team);

        $this->audit->record('billing.cancelled', 'Cancelled the subscription');

        return back()->with('success', 'Subscription cancelled. You keep your plan until the end of the period.');
    }

    public function resume(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        Gate::authorize('manageBilling', $team);

        $this->gateway->resume($team);

        $this->audit->record('billing.resumed', 'Resumed the subscription');

        return back()->with('success', 'Subscription resumed.');
    }

    private function paidPlan(Request $request): Plan
    {
        $plan = Plan::from($request->validate([
            'plan' => ['required', Rule::enum(Plan::class)->except([Plan::Free])],
        ])['plan']);

        return $plan;
    }
}
