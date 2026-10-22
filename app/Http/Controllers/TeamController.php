<?php

namespace App\Http\Controllers;

use App\Models\Team;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class TeamController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Teams/Create');
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255']]);

        $team = Team::createFor($request->user(), $data['name']);
        $audit->recordFor($request->user(), 'team.created', "Created the team “{$team->name}”", $team);

        return redirect()->route('teams.show')->with('success', 'Team created.');
    }

    public function show(Request $request): Response
    {
        $user = $request->user();
        $team = $user->currentTeam;
        Gate::authorize('view', $team);

        return Inertia::render('Teams/Show', [
            'team' => ['id' => $team->id, 'name' => $team->name, 'owner_id' => $team->owner_id],
            'members' => $team->users()->orderBy('name')->get()->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $team->roleOf($member)?->value,
            ]),
            'invitations' => Gate::allows('invite', $team)
                ? $team->invitations()->whereNull('accepted_at')->latest()->get()->map(fn ($invitation) => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role->value,
                    'expires_at' => $invitation->expires_at->toIso8601String(),
                ])
                : [],
            'can' => [
                'invite' => Gate::allows('invite', $team),
                'updateRoles' => Gate::allows('updateMemberRole', $team),
                'transfer' => Gate::allows('transferOwnership', $team),
                'update' => Gate::allows('update', $team),
            ],
        ]);
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        Gate::authorize('update', $team);

        $previous = $team->name;
        $team->update($request->validate(['name' => ['required', 'string', 'max:255']]));

        if ($team->wasChanged('name')) {
            $audit->record('team.renamed', "Renamed the team from “{$previous}” to “{$team->name}”", $team);
        }

        return back()->with('success', 'Team renamed.');
    }

    /** Switch the current team to one the user belongs to. */
    public function switch(Request $request): RedirectResponse
    {
        $request->validate(['team_id' => ['required', 'integer']]);
        $team = Team::find($request->integer('team_id'));

        abort_unless($team && $request->user()->switchTeam($team), 403);

        return redirect()->route('dashboard');
    }
}
