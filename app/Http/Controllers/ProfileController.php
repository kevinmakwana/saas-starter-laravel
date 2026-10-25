<?php

namespace App\Http\Controllers;

use App\Billing\BillingGateway;
use App\Http\Requests\ProfileUpdateRequest;
use App\Mail\EmailChangeConfirmationMail;
use App\Mail\EmailChangeNoticeMail;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\TwoFactor;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request, TwoFactor $twoFactor): Response
    {
        $user = $request->user();

        return Inertia::render('Profile/Edit', [
            'mustVerifyEmail' => $user instanceof MustVerifyEmail,
            'status' => session('status'),
            'pendingEmail' => $user->pendingEmail(),
            'twoFactor' => [
                'enabled' => $user->hasTwoFactorEnabled(),
                'pending' => $user->hasPendingTwoFactor(),
                // Only while setting up: once it is on, the secret is never sent to the browser again.
                'setupKey' => $user->hasPendingTwoFactor() ? $user->two_factor_secret : null,
                'qrSvg' => $user->hasPendingTwoFactor() ? $twoFactor->qrSvg($user) : null,
                'recoveryCodesLeft' => $user->hasTwoFactorEnabled() ? count($user->two_factor_recovery_codes ?? []) : null,
            ],
            // Flashed by the request that made them, so they show exactly once.
            'recoveryCodes' => session('recoveryCodes'),
        ]);
    }

    /**
     * Update the user's profile information. The name changes at once. A new email address does not:
     * it waits for the link mailed to it, and the old address is told about the request.
     */
    public function update(ProfileUpdateRequest $request, AuditLogger $audit): RedirectResponse
    {
        $user = $request->user();
        $user->fill($request->safe()->only('name'));

        $changed = array_keys($user->getDirty());
        $newEmail = $request->changesEmail() ? $request->validated('email') : null;

        if ($newEmail !== null) {
            $user->forceFill(['pending_email' => $newEmail, 'pending_email_expires_at' => now()->addMinutes(User::EMAIL_CHANGE_TTL_MINUTES)]);
        }

        $user->save();

        $this->auditChanges($user, $changed, $audit);

        if ($newEmail !== null) {
            $this->requestEmailChange($user, $newEmail, $audit);
        }

        return Redirect::route('profile.edit');
    }

    private function requestEmailChange(User $user, string $newEmail, AuditLogger $audit): void
    {
        $link = URL::temporarySignedRoute(
            'profile.email.confirm',
            now()->addMinutes(User::EMAIL_CHANGE_TTL_MINUTES),
            ['id' => $user->getKey(), 'hash' => sha1($newEmail)],
        );

        Mail::to($newEmail)->send(new EmailChangeConfirmationMail($user->name, $link));
        Mail::to($user->email)->send(new EmailChangeNoticeMail($user->name, $newEmail));
        $audit->recordFor($user, 'profile.email_change_requested', "Asked to change their email to {$newEmail}");
    }

    /** Names the changed fields. Emails are audited when the change is requested and confirmed, not here. */
    /** @param list<string> $changed */
    private function auditChanges(User $user, array $changed, AuditLogger $audit): void
    {
        $changed = array_values(array_diff($changed, ['updated_at']));

        if ($changed === []) {
            return;
        }

        $audit->recordFor($user, 'profile.updated', 'Updated their profile: '.implode(', ', $changed));
    }

    /**
     * Delete the user's account.
     *
     * Teams cascade-delete with their owner, so an owner must hand over teams that still have
     * other members first. Teams the user owns alone are deleted, after ending their billing.
     */
    public function destroy(Request $request, BillingGateway $billing): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();
        $owned = Team::where('owner_id', $user->getKey())->get();

        $shared = $owned->first(fn (Team $team) => $team->users()->whereKeyNot($user->getKey())->exists());
        if ($shared !== null) {
            throw ValidationException::withMessages([
                'account' => "You still own “{$shared->name}” with other members. Transfer ownership or remove them before deleting your account.",
            ]);
        }

        foreach ($owned as $team) {
            try {
                $billing->terminate($team);
            } catch (Throwable $e) {
                // Deleting the team would leave a subscription that keeps charging a customer nobody can reach.
                report($e);

                throw ValidationException::withMessages([
                    'account' => "We could not end the subscription of “{$team->name}”, so nothing was deleted. Please try again or contact support.",
                ]);
            }
        }

        Auth::logout();

        DB::transaction(function () use ($user, $owned) {
            $owned->each->delete();
            $user->delete();
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
