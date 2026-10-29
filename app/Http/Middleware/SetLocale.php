<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the language of the response: the signed-in user's saved choice, else the one made on this browser before signing
 * in, else what the browser asks for, else the app default. Only languages in config/locales.php are used.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->resolve($request));

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        $available = array_keys((array) config('locales.available'));

        $user = $request->user();
        $candidates = [
            $user instanceof User ? $user->locale : null,
            $request->hasSession() ? $request->session()->get('locale') : null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && in_array($candidate, $available, true)) {
                return $candidate;
            }
        }

        // With no Accept-Language header at all the first language we have is returned, which is the default one.
        $preferred = $request->getPreferredLanguage($available);

        return in_array($preferred, $available, true) ? $preferred : (string) config('app.locale');
    }
}
