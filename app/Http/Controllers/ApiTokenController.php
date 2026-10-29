<?php

namespace App\Http\Controllers;

use App\Models\PersonalAccessToken;
use App\Models\Team;
use App\Services\AuditLogger;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Making and revoking the user's API tokens from their profile. Using one is Api\V1. */
class ApiTokenController extends Controller
{
    /** How long a new token lasts, in days. `never` has no end. */
    private const EXPIRY_DAYS = ['30', '90', '365'];

    public function __construct(private AuditLogger $audit, private TenantContext $tenant) {}

    public function store(Request $request): RedirectResponse
    {
        $user = $this->user($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'team_id' => ['required', 'integer', Rule::in($user->teams()->pluck('teams.id')->all())],
            'access' => ['required', Rule::in(['read', 'write'])],
            'expires' => ['sometimes', Rule::in([...self::EXPIRY_DAYS, 'never'])],
        ]);

        $team = $user->teams()->whereKey($data['team_id'])->firstOrFail();
        $expires = $data['expires'] ?? '90';
        $token = $user->issueApiToken(
            $team,
            $data['name'],
            PersonalAccessToken::abilitiesFor($data['access']),
            $expires === 'never' ? null : now()->addDays((int) $expires),
        );

        $this->record($team, 'apitoken.created', "Created the {$data['access']} API token “{$data['name']}”");

        // Flashed so it shows exactly once: only its hash is kept.
        return redirect()->route('profile.edit')->with('newToken', $token->plainTextToken);
    }

    public function destroy(Request $request, int $token): RedirectResponse
    {
        $record = $this->user($request)->tokens()->whereKey($token)->firstOrFail();

        $record->delete();
        $this->record($record->team, 'apitoken.revoked', "Revoked the API token “{$record->name}”");

        return redirect()->route('profile.edit')->with('success', __('API token revoked.'));
    }

    /** Written to the audit log of the token's team, which is not necessarily the one the user has open. */
    private function record(Team $team, string $action, string $summary): void
    {
        $this->tenant->run($team, fn () => $this->audit->record($action, $summary));
    }
}
