<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Mail\TeamInvitationMail;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\MemberJoined;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeamInvitationController extends Controller
{
    public const TTL_DAYS = 7;

    public function __construct(private AuditLogger $audit) {}

    public function store(Request $request): RedirectResponse
    {
        $team = $this->team($request);
        Gate::authorize('invite', $team);

        $data = $request->validate([
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
            'role' => ['required', Rule::enum(Role::class)->only(Role::invitable())],
        ]);
        $role = Role::from($data['role']);

        Gate::authorize('inviteAs', [$team, $role]);
        if (! $team->invitations()->where('email', $data['email'])->whereNull('accepted_at')->where('expires_at', '>', now())->exists()) {
            Gate::authorize('addMember', $team);
        }

        if ($team->users()->where('email', $data['email'])->exists()) {
            throw ValidationException::withMessages(['email' => __('This person is already on the team.')]);
        }

        $invitation = $team->invitations()->updateOrCreate(
            ['email' => $data['email']],
            [
                'role' => $role,
                'invited_by' => $this->user($request)->getKey(),
                'expires_at' => now()->addDays(self::TTL_DAYS),
                'accepted_at' => null,
            ],
        );

        $this->audit->record('member.invited', "Invited {$invitation->email} as {$role->label()}");

        Mail::to($invitation->email)->locale($this->inviteeLocale($invitation->email))->send(new TeamInvitationMail($invitation));

        return back()->with('success', __('Invitation sent to :email.', ['email' => $invitation->email]));
    }

    /** Sends the email again and starts a fresh expiry, also for an invitation that already ran out. */
    public function resend(Request $request, TeamInvitation $invitation): RedirectResponse
    {
        $team = $this->team($request);
        Gate::authorize('invite', $team);
        abort_unless($invitation->team_id === $team->getKey(), 404);
        abort_if($invitation->accepted_at !== null, 409, __('This invitation was already accepted.'));

        Gate::authorize('inviteAs', [$team, $invitation->role]);
        // A live invitation already holds a seat; an expired one has to fit the plan again.
        if (! $invitation->isPending()) {
            Gate::authorize('addMember', $team);
        }

        $invitation->forceFill(['expires_at' => now()->addDays(self::TTL_DAYS)])->save();

        $this->audit->record('invitation.resent', "Resent the invitation to {$invitation->email}");

        Mail::to($invitation->email)->locale($this->inviteeLocale($invitation->email))->send(new TeamInvitationMail($invitation));

        return back()->with('success', __('Invitation sent again to :email.', ['email' => $invitation->email]));
    }

    public function destroy(Request $request, TeamInvitation $invitation): RedirectResponse
    {
        $team = $this->team($request);
        Gate::authorize('invite', $team);
        abort_unless($invitation->team_id === $team->getKey(), 404);

        $invitation->delete();
        $this->audit->record('invitation.cancelled', "Cancelled invitation for {$invitation->email}");

        return back()->with('success', __('Invitation cancelled.'));
    }

    /** Signed, expiring link from the invitation email. The signed-in user must match the invited address. */
    public function accept(Request $request, TeamInvitation $invitation): RedirectResponse
    {
        $user = $this->user($request);

        abort_unless(strcasecmp($user->email, $invitation->email) === 0, 403, __('This invitation is for a different email address.'));
        abort_unless($invitation->isPending(), 410, __('This invitation has expired or was already used.'));

        $team = $invitation->team;

        // Invitations sent on a bigger plan can outlive a downgrade. The link stays valid for when the owner upgrades.
        $seats = $team->currentPlan()->limit('members');
        if ($seats !== null && $team->users()->count() >= $seats) {
            return redirect()->route('dashboard')
                ->with('error', __(':team is full on its current plan. Ask the owner to upgrade, then open the invitation link again.', ['team' => $team->name]));
        }

        $team->addMember($user, $invitation->role);
        $invitation->forceFill(['accepted_at' => now()])->save();
        $user->switchTeam($team);
        app(TenantContext::class)->run($team, fn () => $this->audit->record('member.joined', "{$user->name} joined as {$invitation->role->label()}", null, $user));

        Notification::send($team->managers()->reject(fn (User $manager) => $manager->is($user)), new MemberJoined($team->getKey(), $team->name, $user->name));

        return redirect()->route('dashboard')->with('success', __('You joined :team.', ['team' => $team->name]));
    }

    /** Someone who already has an account gets the invitation in the language they chose there, anyone else in the inviter's. */
    private function inviteeLocale(string $email): string
    {
        $saved = User::whereRaw('LOWER(email) = ?', [strtolower($email)])->value('locale');

        return is_string($saved) && array_key_exists($saved, (array) config('locales.available')) ? $saved : app()->getLocale();
    }
}
