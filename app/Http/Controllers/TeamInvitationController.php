<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Mail\TeamInvitationMail;
use App\Models\TeamInvitation;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeamInvitationController extends Controller
{
    public const TTL_DAYS = 7;

    public function __construct(private AuditLogger $audit) {}

    public function store(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;
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
            throw ValidationException::withMessages(['email' => 'This person is already on the team.']);
        }

        $invitation = $team->invitations()->updateOrCreate(
            ['email' => $data['email']],
            [
                'role' => $role,
                'invited_by' => $request->user()->getKey(),
                'expires_at' => now()->addDays(self::TTL_DAYS),
                'accepted_at' => null,
            ],
        );

        $this->audit->record('member.invited', "Invited {$invitation->email} as {$role->label()}");

        Mail::to($invitation->email)->send(new TeamInvitationMail($invitation));

        return back()->with('success', "Invitation sent to {$invitation->email}.");
    }

    public function destroy(Request $request, TeamInvitation $invitation): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        Gate::authorize('invite', $team);
        abort_unless($invitation->team_id === $team->getKey(), 404);

        $invitation->delete();
        $this->audit->record('invitation.cancelled', "Cancelled invitation for {$invitation->email}");

        return back()->with('success', 'Invitation cancelled.');
    }

    /** Signed, expiring link from the invitation email. The signed-in user must match the invited address. */
    public function accept(Request $request, TeamInvitation $invitation): RedirectResponse
    {
        $user = $request->user();

        abort_unless(strcasecmp($user->email, $invitation->email) === 0, 403, 'This invitation is for a different email address.');
        abort_unless($invitation->isPending(), 410, 'This invitation has expired or was already used.');

        $team = $invitation->team;

        // Invitations sent on a bigger plan can outlive a downgrade. The link stays valid for when the owner upgrades.
        $seats = $team->currentPlan()->limit('members');
        if ($seats !== null && $team->users()->count() >= $seats) {
            return redirect()->route('dashboard')
                ->with('error', "{$team->name} is full on its current plan. Ask the owner to upgrade, then open the invitation link again.");
        }

        $team->addMember($user, $invitation->role);
        $invitation->forceFill(['accepted_at' => now()])->save();
        $user->switchTeam($team);
        app(TenantContext::class)->run($team, fn () => $this->audit->record('member.joined', "{$user->name} joined as {$invitation->role->label()}", null, $user));

        return redirect()->route('dashboard')->with('success', "You joined {$team->name}.");
    }
}
