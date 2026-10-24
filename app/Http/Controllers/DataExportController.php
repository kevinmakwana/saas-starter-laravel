<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lets users download the personal data the app holds about them as one JSON file.
 *
 * That is their account, their team memberships, the invitations they sent or hold, and the audit entries
 * they caused. Team content (projects, tasks) belongs to the team, not to a person, and billing lives with
 * the team and in Stripe, so neither is included. Secrets and other people's accounts never are.
 */
class DataExportController extends Controller
{
    private const FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    public function __invoke(Request $request, AuditLogger $audit): StreamedResponse
    {
        $request->validate(['password' => ['required', 'current_password']]);

        $user = $request->user();
        $audit->recordFor($user, 'account.exported', 'Downloaded their account data');

        return response()->streamDownload(function () use ($user) {
            $out = fopen('php://output', 'w') ?: throw new RuntimeException('Could not open the response stream.');

            fwrite($out, "{\n");
            fwrite($out, '"exported_at": '.$this->json(now()->toIso8601String()).",\n");
            fwrite($out, '"account": '.$this->json($this->account($user)).",\n");
            fwrite($out, '"teams": '.$this->json($this->teams($user)).",\n");
            fwrite($out, '"invitations": '.$this->json($this->invitations($user)).",\n");
            fwrite($out, '"audit_log": [');

            // Streamed one row at a time: a long-standing user can have a lot of history.
            $first = true;
            foreach (AuditLog::withoutGlobalScopes()->where('user_id', $user->getKey())->lazyById() as $entry) {
                fwrite($out, ($first ? "\n" : ",\n").$this->json([
                    'id' => $entry->id,
                    'team_id' => $entry->tenant_id,
                    'action' => $entry->action,
                    'subject_type' => $entry->subject_type,
                    'subject_id' => $entry->subject_id,
                    'summary' => $entry->summary,
                    'created_at' => $entry->created_at->toIso8601String(),
                ]));
                $first = false;
            }

            fwrite($out, ($first ? '' : "\n")."]\n}\n");
            fclose($out);
        }, 'account-data.json', [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** @return array<string, mixed> */
    private function account(User $user): array
    {
        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'created_at' => $user->created_at?->toIso8601String(),
            'pending_email' => $user->pendingEmail(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function teams(User $user): array
    {
        return array_values($user->teams()->orderBy('teams.id')->get()->map(fn (Team $team) => [
            'id' => $team->getKey(),
            'name' => $team->name,
            'role' => $team->roleOf($user)?->value,
            'is_owner' => $team->owner_id === $user->getKey(),
            'joined_at' => $this->joinedAt($team),
        ])->all());
    }

    private function joinedAt(Team $team): ?string
    {
        $joined = $team->getRelationValue('pivot')?->created_at;

        return $joined === null ? null : Carbon::parse($joined)->toIso8601String();
    }

    /** @return array{sent: list<array<string, mixed>>, received: list<array<string, mixed>>} */
    private function invitations(User $user): array
    {
        $sent = array_values(TeamInvitation::where('invited_by', $user->getKey())->orderBy('id')->get()->map(fn (TeamInvitation $invitation) => [
            'team_id' => $invitation->team_id,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'status' => $invitation->status(),
            'created_at' => $invitation->created_at?->toIso8601String(),
        ])->all());

        $received = array_values(TeamInvitation::with('team')
            ->whereRaw('LOWER(email) = ?', [strtolower($user->email)])
            ->whereNull('accepted_at')->where('expires_at', '>', now())
            ->orderBy('id')->get()->map(fn (TeamInvitation $invitation) => [
                'team_id' => $invitation->team_id,
                'team_name' => $invitation->team->name,
                'role' => $invitation->role->value,
                'expires_at' => $invitation->expires_at->toIso8601String(),
            ])->all());

        return ['sent' => $sent, 'received' => $received];
    }

    private function json(mixed $value): string
    {
        return json_encode($value, self::FLAGS);
    }
}
