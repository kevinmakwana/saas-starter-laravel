<?php

namespace App\Http\Controllers;

use App\Services\AuditLogger;
use App\Services\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Turning two-factor authentication on and off from the profile page. Signing in with it is Auth\TwoFactorChallengeController. */
class TwoFactorController extends Controller
{
    public function __construct(private TwoFactor $twoFactor, private AuditLogger $audit) {}

    /** Starts a setup. Nothing changes about signing in until the first code is confirmed. */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return redirect()->route('profile.edit')->with('error', 'Two-factor authentication is already on.');
        }

        $this->twoFactor->begin($user);

        return redirect()->route('profile.edit');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();

        $codes = $user->hasPendingTwoFactor() ? $this->twoFactor->confirm($user, $request->string('code')->toString()) : null;
        if ($codes === null) {
            throw ValidationException::withMessages(['code' => 'That code is not right. Check the code in your app and try again.']);
        }

        $this->audit->recordFor($user, 'twofactor.enabled', 'Turned on two-factor authentication');

        return redirect()->route('profile.edit')->with('recoveryCodes', $codes);
    }

    public function recoveryCodes(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            return redirect()->route('profile.edit')->with('error', 'Turn on two-factor authentication first.');
        }

        $codes = $this->twoFactor->regenerateRecoveryCodes($user);
        $this->audit->recordFor($user, 'twofactor.recovery_codes_regenerated', 'Made new recovery codes for two-factor authentication');

        return redirect()->route('profile.edit')->with('recoveryCodes', $codes);
    }

    /** Turns it off, or abandons a setup that was never confirmed. */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        $wasOn = $user->hasTwoFactorEnabled();

        $this->twoFactor->disable($user);

        if ($wasOn) {
            $this->audit->recordFor($user, 'twofactor.disabled', 'Turned off two-factor authentication');
        }

        return redirect()->route('profile.edit');
    }
}
