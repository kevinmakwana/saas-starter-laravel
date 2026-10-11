<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Makes sure the signed-in user has a usable current team, or sends them to create one. */
class EnsureHasTeam
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $this->tenant->current() === null) {
            $team = $user->teams()->first();
            if ($team === null || ! $user->switchTeam($team)) {
                return redirect()->route('teams.create');
            }
        }

        return $next($request);
    }
}
