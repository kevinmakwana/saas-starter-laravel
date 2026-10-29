<?php

use App\Enums\Role;
use App\Mail\PaymentFailedMail;
use App\Mail\TeamInvitationMail;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->owner = ownerWithTeam('free');
    $this->team = $this->owner->currentTeam;
});

describe('which language a page is in', function () {
    it('is English unless something says otherwise', function () {
        $this->get('/login')
            ->assertInertia(fn ($page) => $page->where('locale', 'en')->where('locales', [['code' => 'en', 'name' => 'English'], ['code' => 'es', 'name' => 'Español'], ['code' => 'de', 'name' => 'Deutsch']]))
            ->assertSee('<html lang="en"', false);
    });

    it('follows the browser\'s preference for a visitor, when it is one we have', function (string $header, string $expected) {
        $this->withHeader('Accept-Language', $header)->get('/login')
            ->assertInertia(fn ($page) => $page->where('locale', $expected))
            ->assertSee("<html lang=\"{$expected}\"", false);
    })->with([
        'German first' => ['de-DE,de;q=0.9,en;q=0.8', 'de'],
        'Spanish regional' => ['es-MX,es;q=0.9', 'es'],
        'a language we do not have' => ['fr-FR,fr;q=0.9', 'en'],
        'the first one we have' => ['fr-FR,fr;q=0.9,es;q=0.8,de;q=0.7', 'es'],
        'nonsense' => ['***', 'en'],
    ]);

    it('is the visitor\'s own choice once they have made one', function () {
        $this->put('/locale', ['locale' => 'es'])->assertRedirect();

        $this->withHeader('Accept-Language', 'de')->get('/login')->assertInertia(fn ($page) => $page->where('locale', 'es'));
    });

    it('is the saved choice of a signed-in user, whatever the browser says', function () {
        $this->owner->forceFill(['locale' => 'de'])->save();

        $this->actingAs($this->owner)->withHeader('Accept-Language', 'es')->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('locale', 'de'));
    });

    it('falls back to the browser when a saved choice is no longer offered', function () {
        $this->owner->forceFill(['locale' => 'fr'])->save();

        $this->actingAs($this->owner)->get('/dashboard')->assertInertia(fn ($page) => $page->where('locale', 'en'));
    });
});

describe('choosing a language', function () {
    it('is saved on the account of a signed-in user and goes back to the page', function () {
        $this->actingAs($this->owner)->from('/team')->put('/locale', ['locale' => 'de'])->assertRedirect('/team');

        expect($this->owner->fresh()->locale)->toBe('de');
    });

    it('works for a visitor too, without saving anything', function () {
        $this->put('/locale', ['locale' => 'de'])->assertSessionHasNoErrors();

        expect(User::where('locale', 'de')->count())->toBe(0);
    });

    it('carries over from before signing in to the account', function () {
        // Chosen on the sign-in page, then the user signs in: the choice is theirs from now on.
        $this->put('/locale', ['locale' => 'es']);

        $this->post('/login', ['email' => $this->owner->email, 'password' => 'password']);

        expect($this->owner->fresh()->locale)->toBe('es');
    });

    it('refuses a language we do not have', function (mixed $locale) {
        $this->actingAs($this->owner)->put('/locale', ['locale' => $locale])->assertSessionHasErrors('locale');

        expect($this->owner->fresh()->locale)->toBeNull();
    })->with(['fr', '', null, 'en_US', [['de']]]);
});

describe('what is in that language', function () {
    it('has the messages the server shows after an action', function () {
        $this->owner->forceFill(['locale' => 'de'])->save();

        $this->actingAs($this->owner)->post('/projects', ['name' => 'Start'])->assertSessionHas('success', 'Projekt erstellt.');
    });

    it('has the checks on what was typed', function () {
        $this->owner->forceFill(['locale' => 'es'])->save();

        $this->actingAs($this->owner)->post('/projects', ['name' => ''])
            ->assertSessionHasErrors(['name' => 'El campo nombre es obligatorio.']);
    });

    it('has the plan-limit messages', function () {
        $this->owner->forceFill(['locale' => 'de'])->save();
        asTenant($this->team, fn () => Project::factory()->count(3)->create());

        $this->actingAs($this->owner)->post('/projects', ['name' => 'Zu viel'])
            ->assertSessionHas('upgrade', fn (string $message) => str_contains($message, 'Ihr Free-Tarif'));
    });

    it('has the sign-in errors', function () {
        $this->withHeader('Accept-Language', 'es')->post('/login', ['email' => $this->owner->email, 'password' => 'wrong'])
            ->assertSessionHasErrors(['email' => 'Estas credenciales no coinciden con nuestros registros.']);
    });
});

describe('emails', function () {
    it('go out in the language of the person who gets them, when we know it', function () {
        Mail::fake();
        $invitee = User::factory()->create(['locale' => 'de']);
        $this->team->forceFill(['plan' => 'pro'])->save();
        $this->team->addMember($invitee, Role::Member);

        // A payment failure goes to the owner: in the owner's language.
        $this->owner->forceFill(['locale' => 'es'])->save();
        Mail::to($this->owner)->send(new PaymentFailedMail($this->team));

        Mail::assertQueued(PaymentFailedMail::class, fn ($mail) => $mail->locale === 'es' && str_contains($mail->render(), 'pago'));
    });

    it('go out in the sender\'s language when the person has no account to ask', function () {
        Mail::fake();
        $this->owner->forceFill(['locale' => 'es'])->save();

        $this->actingAs($this->owner)->post('/team/invitations', ['email' => 'new@example.test', 'role' => 'member'])->assertSessionHasNoErrors();

        Mail::assertQueued(TeamInvitationMail::class, fn ($mail) => $mail->locale === 'es'
            && str_contains($mail->envelope()->subject, 'Te han invitado')
            && str_contains($mail->render(), 'Aceptar invitación'));
    });

    it('are English by default', function () {
        Mail::fake();

        $this->actingAs($this->owner)->post('/team/invitations', ['email' => 'new@example.test', 'role' => 'member']);

        Mail::assertQueued(TeamInvitationMail::class, fn ($mail) => str_contains($mail->render(), 'Accept invitation'));
    });
});
