<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\TwoFactor;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->user = ownerWithTeam();
    $this->team = $this->user->currentTeam;
});

/** The code an authenticator app would show, $steps 30-second steps from now. */
function otp(User $user, int $steps = 0): string
{
    $google2fa = app(Google2FA::class);

    // The OTP library reads the real clock, so tests pick a step instead of travelling in time.
    return $google2fa->oathTotp((string) $user->fresh()->two_factor_secret, $google2fa->getTimestamp() + $steps);
}

/** Turns 2FA fully on for the user, as the profile page would, and returns the recovery codes. @return list<string> */
function enableTwoFactor(User $user): array
{
    $twoFactor = app(TwoFactor::class);
    $twoFactor->begin($user);
    $user->refresh();
    // Confirm with the previous step's code, so that this and the next two steps are still free for the sign-in tests.
    $codes = $twoFactor->confirm($user, otp($user, -1));
    $user->refresh();
    expect($codes)->not->toBeNull();

    return $codes;
}

/** A session in which the password was entered recently, as `password.confirm` requires. */
function confirmed(): array
{
    return ['auth.password_confirmed_at' => time()];
}

function auditActions(): array
{
    return asTenant(test()->team, fn () => AuditLog::pluck('action')->all());
}

describe('setting up', function () {
    it('needs the password to be confirmed first', function () {
        $this->actingAs($this->user)->post('/profile/two-factor')->assertRedirect(route('password.confirm'));

        expect($this->user->fresh()->two_factor_secret)->toBeNull();
    });

    it('starts a pending setup that does not change signing in yet', function () {
        $this->actingAs($this->user)->withSession(confirmed())->post('/profile/two-factor')->assertRedirect(route('profile.edit'));

        $user = $this->user->fresh();
        expect($user->two_factor_secret)->not->toBeNull()
            ->and($user->two_factor_confirmed_at)->toBeNull()
            ->and($user->hasTwoFactorEnabled())->toBeFalse();

        auth()->logout();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    });

    it('shows the QR code and setup key while pending, and never the secret once enabled', function () {
        $this->actingAs($this->user)->withSession(confirmed())->post('/profile/two-factor');
        $secret = $this->user->fresh()->two_factor_secret;

        $this->actingAs($this->user)->get('/profile')->assertInertia(fn ($page) => $page
            ->where('twoFactor.pending', true)
            ->where('twoFactor.enabled', false)
            ->where('twoFactor.setupKey', $secret)
            ->where('twoFactor.qrSvg', fn ($svg) => str_starts_with($svg, '<svg')));

        $this->actingAs($this->user)->withSession(confirmed())->post('/profile/two-factor/confirm', ['code' => otp($this->user)]);

        $this->actingAs($this->user)->get('/profile')->assertInertia(fn ($page) => $page
            ->where('twoFactor.enabled', true)
            ->where('twoFactor.pending', false)
            ->where('twoFactor.setupKey', null)
            ->where('twoFactor.qrSvg', null)
            ->where('twoFactor.recoveryCodesLeft', 8));
    });

    it('offers the standard authenticator address: the app as issuer, the account, the secret, 6 digits every 30 seconds', function () {
        $this->actingAs($this->user)->withSession(confirmed())->post('/profile/two-factor');
        $user = $this->user->fresh();

        $uri = app(TwoFactor::class)->setupUri($user);
        $parts = parse_url($uri);
        parse_str($parts['query'], $query);

        expect($parts['scheme'])->toBe('otpauth')->and($parts['host'])->toBe('totp')
            ->and(rawurldecode(ltrim($parts['path'], '/')))->toBe(config('app.name').':'.$user->email)
            ->and($query)->toMatchArray(['secret' => $user->two_factor_secret, 'issuer' => config('app.name'), 'algorithm' => 'SHA1', 'digits' => '6', 'period' => '30']);
        expect(app(TwoFactor::class)->setupUri($this->user->fresh()->forceFill(['two_factor_secret' => null])))->toBeNull();
    });

    it('does not restart a setup that is already on', function () {
        enableTwoFactor($this->user);
        $secret = DB::table('users')->where('id', $this->user->id)->value('two_factor_secret');

        $this->actingAs($this->user)->withSession(confirmed())->post('/profile/two-factor')->assertRedirect(route('profile.edit'))->assertSessionHas('error');

        expect(DB::table('users')->where('id', $this->user->id)->value('two_factor_secret'))->toBe($secret);
    });

    it('turns on with the right code, shows the recovery codes once and logs it', function () {
        $this->actingAs($this->user)->withSession(confirmed())->post('/profile/two-factor');

        $this->actingAs($this->user)->withSession(confirmed())->from('/profile')
            ->post('/profile/two-factor/confirm', ['code' => otp($this->user)])
            ->assertSessionHasNoErrors()->assertRedirect(route('profile.edit'));

        $user = $this->user->fresh();
        expect($user->hasTwoFactorEnabled())->toBeTrue()
            ->and($user->two_factor_recovery_codes)->toHaveCount(8)
            ->and(auditActions())->toContain('twofactor.enabled');

        $this->actingAs($user)->get('/profile')->assertInertia(fn ($page) => $page->has('recoveryCodes', 8));
    });

    it('shows the recovery codes only on the page right after setup', function () {
        $this->actingAs($this->user)->withSession(confirmed())->post('/profile/two-factor');
        $this->actingAs($this->user)->post('/profile/two-factor/confirm', ['code' => otp($this->user)]);

        $this->get('/profile')->assertInertia(fn ($page) => $page->has('recoveryCodes', 8));
        $this->get('/profile')->assertInertia(fn ($page) => $page->where('recoveryCodes', null));
    });

    it('refuses a wrong or empty code and stays pending', function () {
        $this->actingAs($this->user)->withSession(confirmed())->post('/profile/two-factor');

        $this->actingAs($this->user)->from('/profile')->post('/profile/two-factor/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->actingAs($this->user)->from('/profile')->post('/profile/two-factor/confirm', ['code' => ''])->assertSessionHasErrors('code');

        expect($this->user->fresh()->hasTwoFactorEnabled())->toBeFalse();
        expect(auditActions())->not->toContain('twofactor.enabled');
    });

    it('has nothing to confirm without a pending setup', function () {
        $this->actingAs($this->user)->from('/profile')->post('/profile/two-factor/confirm', ['code' => '123456'])->assertSessionHasErrors('code');
    });

    it('stores the secret and the recovery codes encrypted', function () {
        enableTwoFactor($this->user);
        $user = $this->user->fresh();
        $row = DB::table('users')->where('id', $user->id)->first();

        expect($row->two_factor_secret)->not->toBe($user->two_factor_secret)
            ->and($row->two_factor_recovery_codes)->not->toContain($user->two_factor_recovery_codes[0]);
    });

    it('keeps the secret out of every shared page prop and the serialized user', function () {
        enableTwoFactor($this->user);
        $secret = $this->user->fresh()->two_factor_secret;
        $code = $this->user->fresh()->two_factor_recovery_codes[0];

        $html = $this->actingAs($this->user)->get('/dashboard')->getContent();
        $profile = $this->actingAs($this->user)->get('/profile')->getContent();

        expect($html)->not->toContain($secret)->not->toContain($code)->not->toContain('two_factor_secret')
            ->and($profile)->not->toContain($secret)->not->toContain($code)
            ->and(array_keys($this->user->fresh()->toArray()))->not->toContain('two_factor_secret', 'two_factor_recovery_codes');
    });

    it('is for signed-in users only', function () {
        $this->post('/profile/two-factor')->assertRedirect('/login');
        $this->post('/profile/two-factor/confirm', ['code' => '123456'])->assertRedirect('/login');
        $this->delete('/profile/two-factor')->assertRedirect('/login');
        $this->post('/profile/two-factor/recovery-codes')->assertRedirect('/login');
    });
});

describe('managing', function () {
    it('replaces the recovery codes, needs the password, and logs it', function () {
        $old = enableTwoFactor($this->user);

        $this->actingAs($this->user)->post('/profile/two-factor/recovery-codes')->assertRedirect(route('password.confirm'));
        expect($this->user->fresh()->two_factor_recovery_codes)->toBe($old);

        $this->actingAs($this->user)->withSession(confirmed())->post('/profile/two-factor/recovery-codes')->assertRedirect(route('profile.edit'));

        $new = $this->user->fresh()->two_factor_recovery_codes;
        expect($new)->toHaveCount(8)->and(array_intersect($old, $new))->toBe([])
            ->and(auditActions())->toContain('twofactor.recovery_codes_regenerated');
        $this->get('/profile')->assertInertia(fn ($page) => $page->has('recoveryCodes', 8));
    });

    it('does not make recovery codes for an account without 2FA', function () {
        $this->actingAs($this->user)->withSession(confirmed())->post('/profile/two-factor/recovery-codes')->assertRedirect(route('profile.edit'))->assertSessionHas('error');

        expect($this->user->fresh()->two_factor_recovery_codes)->toBeNull();
    });

    it('turns off completely, needs the password, and logs it', function () {
        enableTwoFactor($this->user);

        $this->actingAs($this->user)->delete('/profile/two-factor')->assertRedirect(route('password.confirm'));
        expect($this->user->fresh()->hasTwoFactorEnabled())->toBeTrue();

        $this->actingAs($this->user)->withSession(confirmed())->delete('/profile/two-factor')->assertRedirect(route('profile.edit'));

        $user = $this->user->fresh();
        expect($user->hasTwoFactorEnabled())->toBeFalse()
            ->and($user->two_factor_secret)->toBeNull()
            ->and($user->two_factor_recovery_codes)->toBeNull()
            ->and($user->two_factor_confirmed_at)->toBeNull()
            ->and(auditActions())->toContain('twofactor.disabled');

        auth()->logout();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticated();
    });

    it('also abandons a setup that was never confirmed, without logging a disable', function () {
        $this->actingAs($this->user)->withSession(confirmed())->post('/profile/two-factor');

        $this->actingAs($this->user)->withSession(confirmed())->delete('/profile/two-factor');

        expect($this->user->fresh()->two_factor_secret)->toBeNull()->and(auditActions())->not->toContain('twofactor.disabled');
    });
});

describe('signing in', function () {
    beforeEach(function () {
        $this->codes = enableTwoFactor($this->user);
        $this->login = fn (array $extra = []) => $this->post('/login', array_merge(['email' => $this->user->email, 'password' => 'password'], $extra));
    });

    it('asks for a code after the password instead of signing in', function () {
        ($this->login)()->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
        expect(session('login.id'))->toBe($this->user->id)
            ->and(auditActions())->not->toContain('auth.login');
    });

    it('does not reveal 2FA to someone with the wrong password', function () {
        $this->post('/login', ['email' => $this->user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');

        $this->assertGuest();
        expect(session('login.id'))->toBeNull();
    });

    it('shows the challenge only while a sign-in is pending', function () {
        $this->get(route('two-factor.login'))->assertRedirect('/login');

        ($this->login)();
        $this->get(route('two-factor.login'))->assertOk()->assertInertia(fn ($page) => $page->component('Auth/TwoFactorChallenge'));
    });

    it('signs in with the right code, clears the pending state and records the sign-in', function () {
        ($this->login)();

        $this->post(route('two-factor.login'), ['code' => otp($this->user, 1)])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($this->user);
        expect(session('login.id'))->toBeNull()->and(auditActions())->toContain('auth.login');
    });

    it('sends the user where they were headed', function () {
        $this->get('/projects')->assertRedirect('/login');
        ($this->login)();

        $this->post(route('two-factor.login'), ['code' => otp($this->user, 1)])->assertRedirect('/projects');
    });

    it('keeps "remember me" through the challenge', function () {
        ($this->login)(['remember' => true]);

        $response = $this->post(route('two-factor.login'), ['code' => otp($this->user, 1)]);

        $response->assertCookie(Auth::guard()->getRecallerName());
    });

    it('does not remember the user unless they asked', function () {
        ($this->login)();

        $this->post(route('two-factor.login'), ['code' => otp($this->user, 1)])->assertCookieMissing(Auth::guard()->getRecallerName());
    });

    it('refuses a wrong code and stays signed out', function () {
        ($this->login)();

        $this->from(route('two-factor.login'))->post(route('two-factor.login'), ['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertGuest();
        expect(session('login.id'))->toBe($this->user->id);
    });

    it('needs a code or a recovery code', function () {
        ($this->login)();

        $this->post(route('two-factor.login'), [])->assertSessionHasErrors('code');
        $this->assertGuest();
    });

    it('refuses a code that was already used, and any older than the last one used', function () {
        $used = otp($this->user, 1);
        ($this->login)();
        $this->post(route('two-factor.login'), ['code' => $used])->assertRedirect(route('dashboard'));
        auth()->logout();

        ($this->login)();
        $this->post(route('two-factor.login'), ['code' => $used])->assertSessionHasErrors('code');
        $this->post(route('two-factor.login'), ['code' => otp($this->user, 0)])->assertSessionHasErrors('code');
        $this->post(route('two-factor.login'), ['code' => otp($this->user, -1)])->assertSessionHasErrors('code');
        $this->assertGuest();
    });

    it('accepts a newer code after a used one', function () {
        ($this->login)();
        $this->post(route('two-factor.login'), ['code' => otp($this->user, 0)])->assertRedirect(route('dashboard'));
        auth()->logout();

        ($this->login)();
        $this->post(route('two-factor.login'), ['code' => otp($this->user, 1)])->assertRedirect(route('dashboard'));
    });

    it('signs in with a recovery code exactly once', function () {
        ($this->login)();
        $this->post(route('two-factor.login'), ['recovery_code' => $this->codes[0]])->assertRedirect(route('dashboard'));
        auth()->logout();

        expect($this->user->fresh()->two_factor_recovery_codes)->toHaveCount(7)->not->toContain($this->codes[0]);

        ($this->login)();
        $this->post(route('two-factor.login'), ['recovery_code' => $this->codes[0]])->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
        $this->post(route('two-factor.login'), ['recovery_code' => $this->codes[1]])->assertRedirect(route('dashboard'));
    });

    it('ignores case and spaces around a recovery code', function () {
        ($this->login)();

        $this->post(route('two-factor.login'), ['recovery_code' => '  '.strtoupper($this->codes[2]).' '])->assertRedirect(route('dashboard'));
    });

    it('refuses an unknown recovery code', function () {
        ($this->login)();

        $this->post(route('two-factor.login'), ['recovery_code' => 'aaaaaaaaaa-bbbbbbbbbb'])->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
    });

    it('stops guessing after five wrong tries, even for the right code', function () {
        ($this->login)();
        foreach (range(1, 5) as $i) {
            $this->post(route('two-factor.login'), ['code' => '000000'])->assertSessionHasErrors('code');
        }

        $this->post(route('two-factor.login'), ['code' => otp($this->user, 1)])->assertStatus(429);
        $this->assertGuest();
    });

    it('drops a sign-in that waited too long for its code', function () {
        ($this->login)();
        $this->travel(11)->minutes();

        $this->post(route('two-factor.login'), ['code' => otp($this->user, 1)])->assertRedirect('/login');
        $this->assertGuest();
    });

    it('drops the sign-in when 2FA was turned off in between', function () {
        ($this->login)();
        app(TwoFactor::class)->disable($this->user->fresh());

        $this->post(route('two-factor.login'), ['code' => '123456'])->assertRedirect('/login');
        $this->assertGuest();
    });

    it('drops the sign-in when the account is gone', function () {
        ($this->login)();
        DB::table('users')->where('id', $this->user->id)->delete();

        $this->post(route('two-factor.login'), ['code' => '123456'])->assertRedirect('/login');
        $this->assertGuest();
    });

    it('rejects a challenge with no pending sign-in', function () {
        $this->post(route('two-factor.login'), ['code' => otp($this->user, 1)])->assertRedirect('/login');
        $this->assertGuest();
    });

    it('is not for people who are already signed in', function () {
        $this->actingAs($this->user)->get(route('two-factor.login'))->assertRedirect(route('dashboard'));
    });
});

describe('around 2FA', function () {
    it('leaves a password reset alone: 2FA still applies afterwards', function () {
        enableTwoFactor($this->user);
        $this->user->forceFill(['password' => 'a-new-Passw0rd!'])->save();

        $this->post('/login', ['email' => $this->user->email, 'password' => 'a-new-Passw0rd!'])->assertRedirect(route('two-factor.login'));
    });

    it('never puts the secret into the data export', function () {
        enableTwoFactor($this->user);
        $secret = $this->user->fresh()->two_factor_secret;

        $json = $this->actingAs($this->user)->from('/profile')->post('/profile/export', ['password' => 'password'])->streamedContent();

        expect($json)->not->toContain($secret)->not->toContain('two_factor');
    });

    it('lets an account with 2FA delete itself with the usual password', function () {
        enableTwoFactor($this->user);

        $this->actingAs($this->user)->delete('/profile', ['password' => 'password'])->assertRedirect('/');

        expect(User::find($this->user->id))->toBeNull();
    });
});
