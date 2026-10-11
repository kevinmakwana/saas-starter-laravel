<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\FeatureGate;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Usage: ->middleware('feature:audit_log') */
class RequireFeature
{
    public function __construct(private FeatureGate $gate, private TenantContext $tenant) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $team = $request->user() instanceof User ? $this->tenant->current() : null;

        if ($team === null || ! $this->gate->allows($team, $feature)) {
            return redirect()->route('billing.show')
                ->with('upgrade', 'This feature is not included in your current plan. Upgrade to unlock it.');
        }

        return $next($request);
    }
}
