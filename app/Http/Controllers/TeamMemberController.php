<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TeamMemberController extends Controller
{
    public function update(Request $request, User $user): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        Gate::authorize('updateMemberRole', $team);

        $data = $request->validate(['role' => ['required', Rule::enum(Role::class)->only(Role::invitable())]]);

        abort_unless($team->roleOf($user) !== null && $team->roleOf($user) !== Role::Owner, 422);

        $team->users()->updateExistingPivot($user->getKey(), ['role' => $data['role']]);

        return back()->with('success', 'Role updated.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        Gate::authorize('removeMember', [$team, $user]);

        $team->removeMember($user);

        return $request->user()->is($user)
            ? redirect()->route('dashboard')->with('success', 'You left the team.')
            : back()->with('success', 'Member removed.');
    }
}
