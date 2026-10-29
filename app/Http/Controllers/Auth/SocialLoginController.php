<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SocialProviders;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as ProviderUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Signing in or up with Google or GitHub.
 *
 * Someone is found by the provider's own id first. Failing that, by an email address the provider has verified, which
 * links the login to the account that has it (never to an account whose address was not verified here). Otherwise a
 * new account and personal team are made. Two-factor authentication still applies after the provider has vouched for them.
 */
class SocialLoginController extends Controller
{
    public function __construct(private SocialProviders $providers, private AuditLogger $audit) {}

    public function redirect(string $provider): SymfonyRedirect
    {
        $this->ensureEnabled($provider);

        return Socialite::driver($provider)->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $this->ensureEnabled($provider);
        $label = $this->providers->label($provider);

        if ($request->query('error') !== null) {
            return $this->failed("Signing in with {$label} was cancelled.");
        }

        try {
            $remote = Socialite::driver($provider)->user();
        } catch (InvalidStateException) {
            return $this->failed("Signing in with {$label} took too long or was interrupted. Please try again.");
        } catch (Throwable $e) {
            report($e);

            return $this->failed("We could not reach {$label}. Please try again in a moment.");
        }

        $user = SocialAccount::where('provider', $provider)->where('provider_id', (string) $remote->getId())->first()?->user;

        if ($user === null) {
            $email = $this->providers->verifiedEmail($provider, $remote);
            if ($email === null) {
                return $this->failed("{$label} did not give us a verified email address, so we cannot sign you in with it.");
            }

            $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

            if ($user === null) {
                $user = $this->register($remote, $email);
                $this->link($user, $provider, $remote);
            } elseif (! $user->hasVerifiedEmail()) {
                return $this->failed('An account with this email address exists but its address was never verified. Sign in with your password and verify it first.');
            } elseif ($user->socialAccounts()->where('provider', $provider)->exists()) {
                return $this->failed("Your account is already connected to a different {$label} account.");
            } else {
                $this->link($user, $provider, $remote);
                $this->audit->recordFor($user, 'social.linked', "Connected their {$label} login");
            }
        }

        // With two-factor authentication on, the provider is only the first step, as the password is.
        if ($user->hasTwoFactorEnabled()) {
            $request->session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => false,
                'login.expires_at' => now()->addMinutes(TwoFactorChallengeController::WAIT_MINUTES)->getTimestamp(),
            ]);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function register(ProviderUser $remote, string $email): User
    {
        $name = $remote->getName() ?: $remote->getNickname() ?: Str::before($email, '@');

        $user = DB::transaction(function () use ($name, $email) {
            // Nobody knows this password. The person signs in with the provider, or sets their own with "Forgot your password?".
            $user = User::create(['name' => Str::limit((string) $name, 255, ''), 'email' => $email, 'password' => Hash::make(Str::random(40))]);
            // The provider vouched for the address.
            $user->forceFill(['email_verified_at' => now()])->save();
            Team::createFor($user, "{$user->name}'s Team");

            return $user;
        });

        event(new Registered($user));

        return $user;
    }

    private function link(User $user, string $provider, ProviderUser $remote): void
    {
        $user->socialAccounts()->create(['provider' => $provider, 'provider_id' => (string) $remote->getId(), 'email' => $remote->getEmail()]);
    }

    private function ensureEnabled(string $provider): void
    {
        abort_unless($this->providers->isEnabled($provider), 404);
    }

    private function failed(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
