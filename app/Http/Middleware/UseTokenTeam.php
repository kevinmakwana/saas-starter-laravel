<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For API requests the current team is the one the token was made for, whatever team the user has open in the browser.
 * A token whose user is no longer a member of that team gets nothing.
 */
class UseTokenTeam
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user instanceof User ? $user->currentAccessToken() : null;
        $team = $token instanceof PersonalAccessToken ? $token->team : null;

        abort_if($team === null || ! $team->hasMember($user), 403, 'The owner of this token is no longer a member of its team.');

        return $this->tenant->run($team, fn () => $next($request));
    }
}
