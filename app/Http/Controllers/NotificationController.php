<?php

namespace App\Http\Controllers;

use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The header bell: opening one notification, or clearing them all. The list itself is a shared page prop. */
class NotificationController extends Controller
{
    /** Marks it read, switches to the team it is about (if the user still belongs to it) and goes to its page. */
    public function open(Request $request, string $id): RedirectResponse
    {
        $user = $this->user($request);
        $notification = $user->notifications()->whereKey($id)->firstOrFail();
        $notification->markAsRead();

        $teamId = $notification->data['team_id'] ?? null;
        $team = is_int($teamId) ? Team::find($teamId) : null;
        if ($team !== null) {
            $user->switchTeam($team);
        }

        return redirect($this->internalPath($notification->data['url'] ?? null));
    }

    public function readAll(Request $request): RedirectResponse
    {
        $this->user($request)->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }

    /** Only a path inside this app: stored data is ours, but a redirect target is never trusted blindly. */
    private function internalPath(mixed $url): string
    {
        return is_string($url) && preg_match('#^/(?![/\\\\])#', $url) === 1 ? $url : '/dashboard';
    }
}
