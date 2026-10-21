<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TeamOwnershipController extends Controller
{
    public function __construct(private AuditLogger $audit) {}

    public function store(Request $request): RedirectResponse
    {
        $team = $request->user()->currentTeam;
        Gate::authorize('transferOwnership', $team);

        $request->validate(['user_id' => ['required', 'integer']]);
        $newOwner = User::find($request->integer('user_id'));

        if (! $newOwner || $newOwner->is($request->user()) || ! $team->hasMember($newOwner)) {
            throw ValidationException::withMessages(['user_id' => 'Choose another member of this team.']);
        }

        $team->transferOwnership($request->user(), $newOwner);

        $this->audit->record('team.ownership_transferred', "Transferred ownership to {$newOwner->name}");

        return back()->with('success', "{$newOwner->name} is now the owner.");
    }
}
