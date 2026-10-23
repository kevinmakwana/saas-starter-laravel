<?php

namespace App\Http\Controllers;

use App\Billing\BillingGateway;
use App\Mail\TeamDeletedMail;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

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
                'delete' => Gate::allows('delete', $team),
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

    /**
     * Delete the team the owner is working in, with everything in it. Billing is ended first, so a failure
     * there leaves the team untouched rather than a subscription that keeps charging nobody. The audit log
     * goes with the team, so the deletion is not recorded in it; the other members are told by email instead.
     */
    public function destroy(Request $request, BillingGateway $billing): RedirectResponse
    {
        $user = $request->user();
        $team = $user->currentTeam;
        Gate::authorize('delete', $team);

        $request->validate([
            'password' => ['required', 'current_password'],
            'name' => ['required', 'string', fn (string $attribute, mixed $value, \Closure $fail) => hash_equals($team->name, (string) $value)
                || $fail('Type the team name exactly to confirm.')],
        ]);

        try {
            $billing->terminate($team);
        } catch (Throwable $e) {
            report($e);

            throw ValidationException::withMessages([
                'team' => 'We could not end the subscription, so nothing was deleted. Please try again or contact support.',
            ]);
        }

        $name = $team->name;
        $others = $team->users()->whereKeyNot($user->getKey())->get();

        DB::transaction(fn () => $team->delete());

        foreach ($others as $member) {
            Mail::to($member->email)->send(new TeamDeletedMail($name, $user->name));
        }

        // The foreign key cleared current_team_id; move the owner to another team of theirs if there is one.
        $user->refresh();
        $next = $user->teams()->first();

        return $next !== null && $user->switchTeam($next)
            ? redirect()->route('dashboard')->with('success', "“{$name}” was deleted.")
            : redirect()->route('teams.create')->with('success', "“{$name}” was deleted.");
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
