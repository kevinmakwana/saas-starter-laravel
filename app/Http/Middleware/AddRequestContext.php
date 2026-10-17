<?php

namespace App\Http\Middleware;

use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Sentry\State\Scope;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the request id, user id and team id into Laravel's Context, so every log line and error report
 * (and any job dispatched during the request) can be tied back to one request, user and tenant.
 * Only ids go in: no names or emails. The same ids tag Sentry error reports.
 */
class AddRequestContext
{
    public function __construct(private TenantContext $tenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->requestId($request);

        // Forget first: a long-lived worker must not carry the previous request's user into this one.
        Context::forget(['user_id', 'team_id']);
        Context::add('request_id', $requestId);

        $user = $request->user();
        $teamId = $user === null ? null : $this->tenant->id();
        if ($user !== null) {
            Context::add('user_id', $user->getAuthIdentifier());
            Context::addIf('team_id', $teamId);
        }

        // The same ids tag errors in Sentry (a no-op without a DSN). Not the email or name: ids only.
        \Sentry\configureScope(function (Scope $scope) use ($requestId, $user, $teamId): void {
            $scope->setTag('request_id', $requestId);
            $teamId === null ? $scope->removeTag('team_id') : $scope->setTag('team_id', (string) $teamId);
            $user === null ? $scope->removeUser() : $scope->setUser(['id' => (string) $user->getAuthIdentifier()]);
        });

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }

    /** Reuses an id from a load balancer or proxy when it is short and safe, otherwise makes a new one. */
    private function requestId(Request $request): string
    {
        $incoming = (string) $request->header('X-Request-Id');

        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $incoming) === 1 ? $incoming : (string) Str::uuid();
    }
}
