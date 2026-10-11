<?php

namespace App\Http\Middleware;

use App\Services\FeatureGate;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
                'team' => fn () => $this->currentTeam($request),
                'billing' => fn () => $this->billingNotice($request),
                'features' => fn () => ($team = $request->user() ? app(TenantContext::class)->current() : null) ? app(FeatureGate::class)->all($team) : [],
                'teams' => fn () => $request->user()?->teams()->orderBy('name')->get(['teams.id', 'teams.name']),
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'upgrade' => fn () => $request->session()->get('upgrade'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }

    /** @return array{id: int, name: string, role: string|null}|null */
    private function currentTeam(Request $request): ?array
    {
        $user = $request->user();
        $team = $user ? app(TenantContext::class)->current() : null;

        return $team ? ['id' => $team->id, 'name' => $team->name, 'role' => $team->roleOf($user)?->value] : null;
    }

    /** @return array{state: string, grace_ends_at: string|null}|null */
    private function billingNotice(Request $request): ?array
    {
        $team = $request->user() ? app(TenantContext::class)->current() : null;

        return match (true) {
            $team?->inPaymentGrace() => ['state' => 'grace', 'grace_ends_at' => $team->payment_grace_ends_at?->toIso8601String()],
            $team?->paymentOverdue() => ['state' => 'overdue', 'grace_ends_at' => $team->payment_grace_ends_at?->toIso8601String()],
            default => null,
        };
    }
}
