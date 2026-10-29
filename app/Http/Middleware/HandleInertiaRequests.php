<?php

namespace App\Http\Middleware;

use App\Services\FeatureGate;
use App\Services\PlanLimits;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
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
                'overLimit' => fn () => $this->overLimitNotice($request),
                'features' => fn () => ($team = $request->user() ? app(TenantContext::class)->current() : null) ? app(FeatureGate::class)->all($team) : [],
                'teams' => fn () => $request->user()?->teams()->orderBy('name')->get(['teams.id', 'teams.name']),
            ],
            'locale' => app()->getLocale(),
            'locales' => collect((array) config('locales.available'))->map(fn ($name, $code) => ['code' => $code, 'name' => $name])->values()->all(),
            'notifications' => fn () => $this->notifications($request),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'upgrade' => fn () => $request->session()->get('upgrade'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }

    /** @return array{unread: int, items: list<array<string, mixed>>}|null the header bell: the unread count and the newest ten */
    private function notifications(Request $request): ?array
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        return [
            'unread' => $user->unreadNotifications()->count(),
            'items' => array_values($user->notifications()->latest()->limit(10)->get()->map(fn (DatabaseNotification $n) => [
                'id' => $n->id,
                'kind' => $n->data['kind'] ?? 'unknown',
                'params' => $n->data['params'] ?? [],
                'read' => $n->read_at !== null,
                'created_at' => $n->created_at?->toIso8601String(),
            ])->all()),
        ];
    }

    /** @return array{id: int, name: string, role: string|null}|null */
    private function currentTeam(Request $request): ?array
    {
        $user = $request->user();
        $team = $user ? app(TenantContext::class)->current() : null;

        return $team ? ['id' => $team->id, 'name' => $team->name, 'role' => $team->roleOf($user)?->value] : null;
    }

    /** @return array{plan: string, items: list<array{key: string, label: string, used: int, limit: int}>}|null */
    private function overLimitNotice(Request $request): ?array
    {
        $team = $request->user() ? app(TenantContext::class)->current() : null;
        $items = $team ? app(PlanLimits::class)->overages($team) : [];

        return $items === [] ? null : ['plan' => $team->currentPlan()->label(), 'items' => $items];
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
