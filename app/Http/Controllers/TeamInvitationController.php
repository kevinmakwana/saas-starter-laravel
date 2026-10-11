<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Mail\TeamInvitationMail;
use App\Models\TeamInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TeamInvitationController extends Controller
{
    public const TTL_DAYS = 7;

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

        Mail::to($invitation->email)->send(new TeamInvitationMail($invitation));

        return back()->with('success', "Invitation sent to {$invitation->email}.");
    }

    public function destroy(Request $request, TeamInvitation $invitation): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        Gate::authorize('invite', $team);
        abort_unless($invitation->team_id === $team->getKey(), 404);

        $invitation->delete();

        return back()->with('success', 'Invitation cancelled.');
    }

    /** Signed, expiring link from the invitation email. The signed-in user must match the invited address. */
    public function accept(Request $request, TeamInvitation $invitation): RedirectResponse
    {
        $user = $request->user();

        abort_unless(strcasecmp($user->email, $invitation->email) === 0, 403, 'This invitation is for a different email address.');
        abort_unless($invitation->isPending(), 410, 'This invitation has expired or was already used.');

        $team = $invitation->team;
        $team->addMember($user, $invitation->role);
        $invitation->forceFill(['accepted_at' => now()])->save();
        $user->switchTeam($team);

        return redirect()->route('dashboard')->with('success', "You joined {$team->name}.");
    }
}
