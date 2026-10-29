<?php

use App\Models\AuditLog;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as ProviderUser;

beforeEach(function () {
    config([
        'services.github' => ['client_id' => 'gh-id', 'client_secret' => 'gh-secret', 'redirect' => '/auth/github/callback'],
        'services.google' => ['client_id' => 'g-id', 'client_secret' => 'g-secret', 'redirect' => '/auth/google/callback'],
    ]);
});

/** @param array<string, mixed> $attributes */
function providerUser(array $attributes = [], bool $emailVerified = true): ProviderUser
{
    $user = (new ProviderUser)->map(array_merge([
        'id' => '1001',
        'nickname' => 'ada',
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
    ], $attributes));

    // Google says so in its profile; GitHub only ever hands over a verified primary address.
    return $user->setRaw(['email_verified' => $emailVerified]);
}

describe('the buttons', function () {
    it('are offered for the providers that have credentials', function () {
        config(['services.google.client_secret' => null]);

        $this->get('/login')->assertInertia(fn ($page) => $page->where('socialProviders', [['key' => 'github', 'label' => 'GitHub']]));
        $this->get('/register')->assertInertia(fn ($page) => $page->where('socialProviders', [['key' => 'github', 'label' => 'GitHub']]));
    });

    it('are not offered when nothing is configured', function () {
        config(['services.github' => [], 'services.google' => []]);

        $this->get('/login')->assertInertia(fn ($page) => $page->where('socialProviders', []));
    });
});

describe('going to the provider', function () {
    it('sends the browser to the provider', function () {
        Socialite::fake('github', providerUser());

        $this->get('/auth/github/redirect')->assertRedirect('https://socialite.fake/github/authorize');
    });

    it('is not found for a provider without credentials or one we do not know', function () {
        config(['services.google.client_id' => null]);

        $this->get('/auth/google/redirect')->assertNotFound();
        $this->get('/auth/google/callback')->assertNotFound();
        $this->get('/auth/myspace/redirect')->assertNotFound();
    });

    it('is for guests only', function () {
        $this->actingAs(User::factory()->create())->get('/auth/github/redirect')->assertRedirect('/dashboard');
    });
});

describe('a new person', function () {
    it('gets a verified account, a personal team and is signed in', function () {
        Socialite::fake('github', providerUser());

        $this->get('/auth/github/callback')->assertRedirect('/dashboard');

        $user = User::where('email', 'ada@example.test')->sole();
        expect(Auth::id())->toBe($user->id)
            ->and($user->name)->toBe('Ada Lovelace')
            ->and($user->hasVerifiedEmail())->toBeTrue()
            ->and($user->currentTeam)->not->toBeNull()
            ->and($user->currentTeam->name)->toBe("Ada Lovelace's Team")
            ->and($user->currentTeam->owner_id)->toBe($user->id)
            ->and($user->socialAccounts()->sole()->only(['provider', 'provider_id', 'email']))->toBe(['provider' => 'github', 'provider_id' => '1001', 'email' => 'ada@example.test']);
        // Nobody knows the password that was set, so the only way in is the provider (or "forgot password").
        expect(Auth::attempt(['email' => 'ada@example.test', 'password' => '']))->toBeFalse();
    });

    it('is named after the provider nickname or the address when there is no name', function () {
        Socialite::fake('github', providerUser(['name' => null, 'nickname' => 'ada-l']));
        $this->get('/auth/github/callback');
        expect(User::where('email', 'ada@example.test')->sole()->name)->toBe('ada-l');

        auth()->logout();
        Socialite::fake('github', providerUser(['id' => '2', 'name' => null, 'nickname' => null, 'email' => 'grace@example.test']));
        $this->get('/auth/github/callback');
        expect(User::where('email', 'grace@example.test')->sole()->name)->toBe('grace');
    });

    it('is stored with a lowercase address', function () {
        Socialite::fake('google', providerUser(['email' => 'Ada@Example.TEST']));

        $this->get('/auth/google/callback')->assertRedirect('/dashboard');

        expect(User::sole()->email)->toBe('ada@example.test');
    });

    it('is refused when the provider gives no address or says it is unverified', function (?string $email, bool $verified) {
        Socialite::fake('google', providerUser(['email' => $email], $verified));

        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('email');

        expect(User::count())->toBe(0)->and(Auth::check())->toBeFalse();
    })->with([
        'no address' => [null, true],
        'unverified' => ['ada@example.test', false],
    ]);

    it('is written to the audit log of their new team', function () {
        Socialite::fake('github', providerUser());

        $this->get('/auth/github/callback');

        $actions = AuditLog::withoutGlobalScopes()->pluck('action')->all();
        expect($actions)->toContain('auth.login');
    });
});

describe('someone who has been here before', function () {
    it('signs in to the account their provider login is linked to', function () {
        $user = User::factory()->create(['email' => 'old@example.test']);
        $user->socialAccounts()->create(['provider' => 'github', 'provider_id' => '1001', 'email' => 'old@example.test']);
        // The provider address has changed since; the link is by the provider's id, not by address.
        Socialite::fake('github', providerUser(['email' => 'new-address@example.test']));

        $this->get('/auth/github/callback')->assertRedirect('/dashboard');

        expect(Auth::id())->toBe($user->id)->and(User::count())->toBe(1)->and(SocialAccount::count())->toBe(1);
    });

    it('is linked to the existing account with the same verified address', function () {
        $user = ownerWithTeam();
        $user->forceFill(['email' => 'ada@example.test'])->save();
        Socialite::fake('google', providerUser());

        $this->get('/auth/google/callback')->assertRedirect('/dashboard');

        expect(Auth::id())->toBe($user->id)
            ->and(User::count())->toBe(1)
            ->and($user->socialAccounts()->sole()->provider)->toBe('google');
        $entry = AuditLog::withoutGlobalScopes()->where('action', 'social.linked')->sole();
        expect($entry->user_id)->toBe($user->id)->and($entry->summary)->toContain('Google');
    });

    it('is not linked to an account whose address was never verified', function () {
        // Someone could have signed up with this address first and set a password of their own.
        $squatter = User::factory()->unverified()->create(['email' => 'ada@example.test']);
        Socialite::fake('google', providerUser());

        $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('email');

        expect(Auth::check())->toBeFalse()->and($squatter->socialAccounts()->count())->toBe(0);
    });

    it('is asked for the second factor when they have one, before being signed in', function () {
        $user = ownerWithTeam();
        $user->forceFill(['email' => 'ada@example.test', 'two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
        Socialite::fake('github', providerUser());

        $this->get('/auth/github/callback')->assertRedirect(route('two-factor.login'));

        expect(Auth::check())->toBeFalse()
            ->and(session('login.id'))->toBe($user->id)
            ->and($user->socialAccounts()->count())->toBe(1);
    });
});

describe('when it goes wrong', function () {
    it('goes back to the sign-in page when the person says no at the provider', function () {
        $this->get('/auth/github/callback?error=access_denied')->assertRedirect('/login')->assertSessionHasErrors('email');

        expect(User::count())->toBe(0);
    });

    it('goes back to the sign-in page when the provider fails', function () {
        Socialite::fake('github', fn () => throw new RuntimeException('Bad gateway'));

        $this->get('/auth/github/callback')->assertRedirect('/login')->assertSessionHasErrors('email');

        expect(User::count())->toBe(0);
    });
});

describe('connected accounts', function () {
    it('are listed on the profile page and can be disconnected', function () {
        $user = ownerWithTeam();
        $link = $user->socialAccounts()->create(['provider' => 'github', 'provider_id' => '7', 'email' => 'ada@example.test']);

        $this->actingAs($user)->get('/profile')->assertInertia(fn ($page) => $page
            ->has('socialAccounts', 1, fn ($a) => $a->where('id', $link->id)->where('provider', 'GitHub')->where('email', 'ada@example.test')->etc()));

        $this->actingAs($user)->delete("/profile/social-accounts/{$link->id}")->assertRedirect(route('profile.edit'));

        expect(SocialAccount::count())->toBe(0);
        expect(AuditLog::withoutGlobalScopes()->where('action', 'social.unlinked')->count())->toBe(1);
    });

    it('cannot be disconnected by someone else', function () {
        $owner = ownerWithTeam();
        $link = $owner->socialAccounts()->create(['provider' => 'github', 'provider_id' => '7', 'email' => 'a@example.test']);

        $this->actingAs(ownerWithTeam())->delete("/profile/social-accounts/{$link->id}")->assertNotFound();

        expect(SocialAccount::count())->toBe(1);
    });
});

describe('personal data', function () {
    it('are in the data download', function () {
        $user = ownerWithTeam();
        $user->socialAccounts()->create(['provider' => 'google', 'provider_id' => '9', 'email' => 'ada@example.test']);

        $json = $this->actingAs($user)->from('/profile')->post('/profile/export', ['password' => 'password'])->streamedContent();
        $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        expect($data['social_accounts'])->toHaveCount(1)
            ->and(array_keys($data['social_accounts'][0]))->toBe(['provider', 'email', 'connected_at'])
            ->and($data['social_accounts'][0]['provider'])->toBe('google');
    });

    it('go with the account', function () {
        $solo = ownerWithTeam();
        $solo->socialAccounts()->create(['provider' => 'google', 'provider_id' => '9', 'email' => 'a@example.test']);

        $this->actingAs($solo)->delete('/profile', ['password' => 'password'])->assertRedirect('/');

        expect(SocialAccount::count())->toBe(0);
    });
});
