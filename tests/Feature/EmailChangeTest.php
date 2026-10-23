<?php

use App\Mail\EmailChangeConfirmationMail;
use App\Mail\EmailChangeNoticeMail;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Mail::fake();
    $this->user = ownerWithTeam();
    $this->old = $this->user->email;
});

/** @param array<string, mixed> $input */
function requestEmailChange(array $input = [], ?User $as = null): TestResponse
{
    $user = $as ?? test()->user;

    return test()->actingAs($user)->from('/profile')->patch('/profile', array_merge([
        'name' => $user->name, 'email' => 'new@example.test', 'current_password' => 'password',
    ], $input));
}

/** The confirmation link that was mailed to $address. */
function mailedLink(string $address = 'new@example.test'): string
{
    $url = null;
    Mail::assertQueued(EmailChangeConfirmationMail::class, function (EmailChangeConfirmationMail $mail) use ($address, &$url) {
        $url = $mail->url;

        return $mail->hasTo($address);
    });

    return $url;
}

describe('asking for a change', function () {
    it('changes the name straight away and asks for nothing more', function () {
        $this->actingAs($this->user)->patch('/profile', ['name' => 'Renamed', 'email' => $this->old])->assertSessionHasNoErrors();

        expect($this->user->fresh()->name)->toBe('Renamed')
            ->and($this->user->fresh()->pending_email)->toBeNull();
        Mail::assertNothingQueued();
    });

    it('does not touch the address yet, only remembers the new one for an hour', function () {
        requestEmailChange(['name' => 'Renamed'])->assertSessionHasNoErrors()->assertRedirect('/profile');

        $user = $this->user->fresh();
        expect($user->email)->toBe($this->old)
            ->and($user->email_verified_at)->not->toBeNull()
            ->and($user->name)->toBe('Renamed')
            ->and($user->pending_email)->toBe('new@example.test')
            ->and($user->pending_email_expires_at->between(now()->addMinutes(59), now()->addMinutes(61)))->toBeTrue();
    });

    it('mails a signed link to the new address and a heads-up to the old one', function () {
        requestEmailChange();

        $link = mailedLink();
        expect(URL::hasValidSignature(Request::create($link)))->toBeTrue();
        Mail::assertQueued(EmailChangeConfirmationMail::class, fn ($mail) => $mail->queue === 'mail');
        Mail::assertQueued(EmailChangeNoticeMail::class, fn (EmailChangeNoticeMail $mail) => $mail->hasTo($this->old) && ! $mail->completed && $mail->newEmail === 'new@example.test');
        Mail::assertQueued(EmailChangeNoticeMail::class, 1);
    });

    it('needs the current password', function () {
        requestEmailChange(['current_password' => ''])->assertSessionHasErrors('current_password');
        requestEmailChange(['current_password' => 'wrong'])->assertSessionHasErrors('current_password');

        expect($this->user->fresh()->pending_email)->toBeNull();
        Mail::assertNothingQueued();
    });

    it('rejects an address that another account already uses', function () {
        $taken = User::factory()->create();

        requestEmailChange(['email' => $taken->email])->assertSessionHasErrors('email');

        expect($this->user->fresh()->pending_email)->toBeNull();
        Mail::assertNothingQueued();
    });

    it('rejects an address that is not lowercase or not an email', function () {
        requestEmailChange(['email' => 'New@Example.test'])->assertSessionHasErrors('email');
        requestEmailChange(['email' => 'nope'])->assertSessionHasErrors('email');
    });

    it('lets a newer request replace the pending one and kills the older link', function () {
        requestEmailChange();
        $first = mailedLink();
        requestEmailChange(['email' => 'other@example.test']);

        expect($this->user->fresh()->pending_email)->toBe('other@example.test');
        $this->actingAs($this->user)->get($first)->assertStatus(410);
    });

    it('records the request in the audit log without the old address', function () {
        requestEmailChange();

        $summary = asTenant($this->user->currentTeam, fn () => AuditLog::where('action', 'profile.email_change_requested')->first()?->summary);
        expect($summary)->toBe('Asked to change their email to new@example.test')->not->toContain($this->old);
    });

    it('is rate limited per user, whatever the password', function () {
        foreach (range(1, 5) as $i) {
            requestEmailChange(['current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        }

        requestEmailChange()->assertStatus(429);
        // Saving the name alone is not limited.
        $this->actingAs($this->user)->patch('/profile', ['name' => 'Still fine', 'email' => $this->old])->assertSessionHasNoErrors();
    });
});

describe('confirming', function () {
    beforeEach(function () {
        requestEmailChange();
        $this->link = mailedLink();
        Mail::fake();
    });

    it('switches the address, verifies it and clears the request', function () {
        $this->actingAs($this->user)->get($this->link)->assertRedirect(route('profile.edit'))->assertSessionHas('success');

        $user = $this->user->fresh();
        expect($user->email)->toBe('new@example.test')
            ->and($user->email_verified_at)->not->toBeNull()
            ->and($user->pending_email)->toBeNull()
            ->and($user->pending_email_expires_at)->toBeNull();
    });

    it('verifies an address that was never verified before', function () {
        $this->user->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($this->user)->get($this->link);

        expect($this->user->fresh()->hasVerifiedEmail())->toBeTrue();
    });

    it('tells the old address it was changed', function () {
        $this->actingAs($this->user)->get($this->link);

        Mail::assertQueued(EmailChangeNoticeMail::class, fn (EmailChangeNoticeMail $mail) => $mail->hasTo($this->old) && $mail->completed && $mail->newEmail === 'new@example.test');
        Mail::assertQueued(EmailChangeNoticeMail::class, 1);
    });

    it('records the change in the audit log', function () {
        $this->actingAs($this->user)->get($this->link);

        $summary = asTenant($this->user->currentTeam, fn () => AuditLog::where('action', 'profile.updated')->latest('id')->first()?->summary);
        expect($summary)->toBe('Updated their profile: email (now new@example.test)');
    });

    it('works only once', function () {
        $this->actingAs($this->user)->get($this->link)->assertRedirect();
        $this->actingAs($this->user)->get($this->link)->assertStatus(410);
    });

    it('lets the user sign in with the new address afterwards', function () {
        $this->actingAs($this->user)->get($this->link);
        auth()->logout();

        $this->post('/login', ['email' => 'new@example.test', 'password' => 'password'])->assertRedirect();
        $this->assertAuthenticated();
    });

    it('refuses a tampered or unsigned link', function () {
        $this->actingAs($this->user)->get($this->link.'x')->assertForbidden();
        $this->actingAs($this->user)->get(route('profile.email.confirm'))->assertForbidden();

        expect($this->user->fresh()->email)->toBe($this->old);
    });

    it('refuses a link opened by somebody else', function () {
        $this->actingAs(User::factory()->create())->get($this->link)->assertForbidden();

        expect($this->user->fresh()->email)->toBe($this->old);
    });

    it('sends a signed-out visitor to log in first', function () {
        auth()->logout();

        $this->get($this->link)->assertRedirect('/login');

        expect($this->user->fresh()->email)->toBe($this->old);
    });

    it('stops working after an hour', function () {
        $this->travel(61)->minutes();

        $this->actingAs($this->user)->get($this->link)->assertForbidden();
        expect($this->user->fresh()->email)->toBe($this->old);
    });

    it('refuses once the request itself has run out', function () {
        $this->user->forceFill(['pending_email_expires_at' => now()->subMinute()])->save();

        $this->actingAs($this->user)->get($this->link)->assertStatus(410);
        expect($this->user->fresh()->email)->toBe($this->old);
    });

    it('refuses when someone took the address in the meantime, and drops the request', function () {
        User::factory()->create(['email' => 'new@example.test']);

        $this->actingAs($this->user)->get($this->link)->assertRedirect(route('profile.edit'))->assertSessionHas('error');

        $user = $this->user->fresh();
        expect($user->email)->toBe($this->old)->and($user->pending_email)->toBeNull();
        Mail::assertNothingQueued();
    });

    it('is not affected by a request that was cancelled', function () {
        $this->actingAs($this->user)->delete(route('profile.email.cancel'))->assertRedirect();

        $this->actingAs($this->user)->get($this->link)->assertStatus(410);
        expect($this->user->fresh()->email)->toBe($this->old);
    });
});

describe('cancelling', function () {
    it('drops the pending request', function () {
        requestEmailChange();

        $this->actingAs($this->user)->delete(route('profile.email.cancel'))->assertRedirect(route('profile.edit'));

        expect($this->user->fresh()->pending_email)->toBeNull()->and($this->user->fresh()->pending_email_expires_at)->toBeNull();
    });

    it('is harmless when nothing is pending, and for guests it needs a login', function () {
        $this->actingAs($this->user)->delete(route('profile.email.cancel'))->assertRedirect(route('profile.edit'));
        auth()->logout();
        $this->delete(route('profile.email.cancel'))->assertRedirect('/login');
    });
});

describe('the profile page', function () {
    it('shows a pending change, but not an expired one', function () {
        requestEmailChange();

        $this->actingAs($this->user)->get('/profile')->assertInertia(fn ($page) => $page->where('pendingEmail', 'new@example.test'));

        $this->travel(61)->minutes();
        $this->actingAs($this->user->fresh())->get('/profile')->assertInertia(fn ($page) => $page->where('pendingEmail', null));
    });
});

describe('the emails', function () {
    it('render with the link and the addresses', function () {
        $confirmation = new EmailChangeConfirmationMail('Ada', 'https://app.test/confirm?x=1');
        $confirmation->assertSeeInHtml('https://app.test/confirm?x=1');
        $confirmation->assertSeeInHtml('Ada');

        $requested = new EmailChangeNoticeMail('Ada', 'new@example.test', completed: false);
        $requested->assertSeeInHtml('new@example.test');
        expect($requested->envelope()->subject)->toBe('Someone asked to change your email address');

        $completed = new EmailChangeNoticeMail('Ada', 'new@example.test', completed: true);
        $completed->assertSeeInHtml('new@example.test');
        expect($completed->envelope()->subject)->toBe('Your email address was changed');
    });
});
