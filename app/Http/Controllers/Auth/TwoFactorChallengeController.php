<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The second step of signing in for an account with two-factor authentication. The password step
 * (AuthenticatedSessionController) only parks the user in the session; nobody is signed in until a code is accepted.
 */
class TwoFactorChallengeController extends Controller
{
    /** How long a correct password stays valid while waiting for the code. */
    public const WAIT_MINUTES = 10;

    public function create(Request $request): Response|RedirectResponse
    {
        return $this->pendingUser($request) === null
            ? redirect()->route('login')
            : Inertia::render('Auth/TwoFactorChallenge');
    }

    public function store(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $user = $this->pendingUser($request);
        if ($user === null) {
            return redirect()->route('login');
        }

        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:32'],
            'recovery_code' => ['nullable', 'string', 'max:64'],
        ]);

        if (filled($data['recovery_code'] ?? null)) {
            $accepted = $twoFactor->useRecoveryCode($user, $data['recovery_code']);
            $field = 'recovery_code';
        } else {
            $accepted = filled($data['code'] ?? null) && $twoFactor->verify($user, $data['code']);
            $field = 'code';
        }

        if (! $accepted) {
            throw ValidationException::withMessages([
                $field => $field === 'code' && blank($data['code'] ?? null)
                    ? __('Enter the code from your authenticator app, or a recovery code.')
                    : __('That code is not valid.'),
            ]);
        }

        $remember = (bool) $request->session()->get('login.remember', false);
        $request->session()->forget(['login.id', 'login.remember', 'login.expires_at']);

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /** The user who gave the right password a moment ago, or null when there is none, it timed out, or 2FA is no longer on. */
    private function pendingUser(Request $request): ?User
    {
        $session = $request->session();
        $id = $session->get('login.id');
        $expiresAt = $session->get('login.expires_at');

        $user = is_int($id) && is_int($expiresAt) && $expiresAt >= now()->getTimestamp() ? User::find($id) : null;

        if ($user === null || ! $user->hasTwoFactorEnabled()) {
            $session->forget(['login.id', 'login.remember', 'login.expires_at']);

            return null;
        }

        return $user;
    }
}
